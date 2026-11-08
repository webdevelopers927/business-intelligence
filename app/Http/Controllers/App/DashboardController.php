<?php

namespace App\Http\Controllers\App;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\InsightGenerator;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Support\AnalyticsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Executive dashboard. KPIs render with the page; the heavier panels are
 * deferred props in four groups that load in parallel, so the layout and
 * skeletons appear immediately. Until the organization has sales data, the
 * setup checklist (OverviewController) is shown instead.
 */
final class DashboardController extends Controller
{
    private const KPIS = ['revenue', 'net_profit', 'operating_expenses', 'orders'];

    private const SECONDARY = ['gross_margin', 'profit_margin', 'average_order_value', 'units_sold'];

    public function __invoke(Request $request, TenantContext $tenant, AnalyticsEngine $engine, InsightGenerator $insights): Response
    {
        if (! $engine->hasData()) {
            return app(OverviewController::class)($tenant);
        }

        $q = AnalyticsQuery::fromRequest($request, $tenant);
        $range = $q->range;
        $filters = $q->filters;
        $previous = $range->previous();

        $kpis = $engine->kpis($range, $filters, [...self::KPIS, ...self::SECONDARY]);
        foreach ($kpis as $key => $kpi) {
            $kpis[$key]['ignored_filters'] = $engine->ignoredFilters($key, $filters);
        }

        return Inertia::render('app/Executive', [
            'filters' => $q->state(),
            'options' => AnalyticsQuery::options($tenant),
            'period' => [
                'current' => $range->toArray(),
                'previous' => $previous->toArray(),
                'preset_label' => $range->presetLabel(),
                'granularity' => $range->granularity()->value,
                'data_through' => $engine->lastSalesDate(),
            ],
            'kpis' => array_values(array_intersect_key($kpis, array_flip(self::KPIS))),
            'secondary' => array_values(array_intersect_key($kpis, array_flip(self::SECONDARY))),

            'trend' => Inertia::defer(fn () => [
                'revenue' => $engine->series($range, $filters, ['revenue'], compare: true),
                // Expenses are posted monthly (rent, payroll), so the comparison
                // uses monthly buckets over the trailing twelve months.
                'revenue_vs_expenses' => $engine->series(
                    DateRange::custom($range->to->subMonthsNoOverflow(11)->startOfMonth(), $range->to),
                    $filters,
                    ['revenue', 'operating_expenses', 'net_profit'],
                    Granularity::Month,
                ),
            ], 'trend'),

            'breakdowns' => Inertia::defer(fn () => $this->breakdowns($engine, $q), 'breakdowns'),

            'tables' => Inertia::defer(fn () => [
                'top_products' => $this->topProducts($engine, $q),
                'recent_transactions' => $this->recentTransactions($tenant, $q),
            ], 'tables'),

            'insights' => Inertia::defer(fn () => $insights->forDashboard($range, $filters), 'insights'),
        ]);
    }

    /** @return array<string, mixed> */
    private function breakdowns(AnalyticsEngine $engine, AnalyticsQuery $q): array
    {
        $targets = $engine->targets($q->range);
        $branches = array_values(array_filter(
            $engine->breakdown($q->range, $q->filters, 'branch', ['revenue', 'net_profit'], compare: true),
            fn ($row) => $row['label'] !== 'Head office',
        ));

        $branches = array_map(fn ($row) => [
            ...$row,
            'target' => isset($targets[(string) $row['id']]) ? round($targets[(string) $row['id']], 2) : null,
        ], $branches);

        $categories = $engine->breakdown($q->range, $q->filters, 'category', ['revenue', 'units_sold']);
        $total = array_sum(array_map(fn ($c) => $c['values']['revenue'] ?? 0, $categories));

        return [
            'branches' => $branches,
            'categories' => array_map(fn ($c) => [...$c, 'share' => $total > 0 ? round($c['values']['revenue'] / $total * 100, 1) : null], $categories),
            'explore_month' => $q->range->to->format('Y-m'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function topProducts(AnalyticsEngine $engine, AnalyticsQuery $q): array
    {
        $rows = $engine->breakdown($q->range, $q->filters, 'product', ['revenue', 'units_sold', 'gross_profit'], limit: 10);
        $total = (float) $engine->totals($q->range, $q->filters)['revenue'];
        $meta = DB::table('products as p')
            ->join('product_categories as c', 'c.id', '=', 'p.product_category_id')
            ->whereIn('p.id', array_column($rows, 'id'))
            ->get(['p.id', 'p.sku', 'c.name as category'])
            ->keyBy('id');

        return array_map(fn ($row) => [
            'id' => $row['id'],
            'name' => $row['label'],
            'sku' => $meta[$row['id']]->sku ?? null,
            'category' => $meta[$row['id']]->category ?? null,
            'units' => $row['values']['units_sold'],
            'revenue' => $row['values']['revenue'],
            'gross_profit' => $row['values']['gross_profit'],
            'share' => $total > 0 ? round($row['values']['revenue'] / $total * 100, 1) : null,
        ], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function recentTransactions(TenantContext $tenant, AnalyticsQuery $q): array
    {
        $timezone = $tenant->organization()->timezone;

        return DB::table('sales_transactions as t')
            ->join('branches as b', 'b.id', '=', 't.branch_id')
            ->where('t.organization_id', $tenant->id())
            ->whereBetween('t.business_date', [$q->range->from->toDateString(), $q->range->to->toDateString()])
            ->when($q->filters->branchIds !== [], fn ($w) => $w->whereIn('t.branch_id', $q->filters->branchIds))
            ->when($q->filters->categoryIds !== [], fn ($w) => $w->whereExists(fn ($s) => $s->selectRaw('1')
                ->from('sales_transaction_items as i')
                ->join('products as p', 'p.id', '=', 'i.product_id')
                ->whereColumn('i.sales_transaction_id', 't.id')
                ->whereIn('p.product_category_id', $q->filters->categoryIds)))
            ->orderByDesc('t.occurred_at')
            ->limit(10)
            ->get(['t.id', 't.reference', 't.occurred_at', 'b.name as branch', 't.items_count', 't.net_amount', 't.status', 't.channel'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'reference' => $t->reference,
                // Stored in UTC; shown in the organization's timezone.
                'occurred_at' => CarbonImmutable::parse($t->occurred_at, 'UTC')->setTimezone($timezone)->toIso8601String(),
                'branch' => $t->branch,
                'items' => (int) $t->items_count,
                'amount' => (float) $t->net_amount,
                'status' => $t->status,
                'channel' => $t->channel,
            ])
            ->all();
    }
}
