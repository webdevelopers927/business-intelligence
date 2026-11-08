<?php

namespace App\Http\Controllers\App;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drill-down explorer (docs/ROUTES.md, PRD DRL-1):
 *   months → branches in a month → categories in a branch → transactions.
 *
 * The whole path lives in the URL (?month=&branch=&category=), so every level
 * is shareable and survives a reload; breadcrumbs pop levels.
 */
final class ExploreController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, AnalyticsEngine $engine): Response
    {
        $org = $tenant->organization();
        $today = CarbonImmutable::now($org->timezone)->startOfDay();

        $month = $this->month($request->query('month'), $today);
        $branch = $month ? $this->entity('branches', $request->query('branch'), $tenant) : null;
        $category = $branch ? $this->entity('product_categories', $request->query('category'), $tenant) : null;

        $level = match (true) {
            $category !== null => 'transactions',
            $branch !== null => 'categories',
            $month !== null => 'branches',
            default => 'months',
        };

        $range = $month
            ? DateRange::month($month, $today)
            : DateRange::custom($today->subMonthsNoOverflow(11)->startOfMonth(), $today);
        $filters = new Filters(branchIds: $branch ? [$branch->id] : [], categoryIds: $category ? [$category->id] : []);

        $crumbs = [['label' => 'Last 12 months', 'params' => []]];
        if ($month) {
            $crumbs[] = ['label' => $month->format('F Y'), 'params' => ['month' => $month->format('Y-m')]];
        }
        if ($branch) {
            $crumbs[] = ['label' => $branch->name, 'params' => ['month' => $month->format('Y-m'), 'branch' => $branch->id]];
        }
        if ($category) {
            $crumbs[] = ['label' => $category->name, 'params' => ['month' => $month->format('Y-m'), 'branch' => $branch->id, 'category' => $category->id]];
        }

        return Inertia::render('app/Explore', [
            'level' => $level,
            'crumbs' => $crumbs,
            'context' => [
                'month' => $month?->format('Y-m'),
                'branch' => $branch?->id,
                'category' => $category?->id,
                'range_label' => $range->label(),
                'comparison_label' => DateRange::formatSpan($range->previous()->from, $range->previous()->to),
            ],
            'totals' => $engine->kpis($range, $filters, ['revenue', 'orders', 'average_order_value', 'gross_margin']),
            'data' => Inertia::defer(fn () => match ($level) {
                'months' => $engine->series($range, $filters, ['revenue', 'orders'], Granularity::Month),
                'branches' => $engine->breakdown($range, $filters, 'branch', ['revenue', 'orders', 'gross_margin'], compare: true),
                'categories' => $engine->breakdown($range, $filters, 'category', ['revenue', 'units_sold', 'gross_margin'], compare: true),
                'transactions' => $this->transactions($tenant, $range, $filters, (int) $request->integer('page', 1)),
            }),
        ]);
    }

    private function month(mixed $value, CarbonImmutable $today): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value, $today->timezone);
        } catch (\Throwable) {
            return null; // Carbon 3 throws on malformed input in strict mode
        }

        return $month === null || $month->gt($today) || $month->lt($today->subYears(3)) ? null : $month;
    }

    private function entity(string $table, mixed $id, TenantContext $tenant): ?object
    {
        return is_numeric($id)
            ? DB::table($table)->where('organization_id', $tenant->id())->where('id', (int) $id)->first(['id', 'name'])
            : null;
    }

    /** @return array{rows: list<array<string, mixed>>, meta: array<string, int|null>} */
    private function transactions(TenantContext $tenant, DateRange $range, Filters $filters, int $page): array
    {
        $timezone = $tenant->organization()->timezone;
        $paginator = DB::table('sales_transaction_items as i')
            ->join('sales_transactions as t', 't.id', '=', 'i.sales_transaction_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.organization_id', $tenant->id())
            ->where('t.status', 'completed')
            ->whereBetween('i.business_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->whereIn('i.branch_id', $filters->branchIds)
            ->whereIn('p.product_category_id', $filters->categoryIds)
            ->orderByDesc('t.occurred_at')
            ->orderBy('i.id')
            ->paginate(25, ['i.id', 't.reference', 't.occurred_at', 't.channel', 'p.sku', 'p.name as product', 'i.quantity', 'i.unit_price', 'i.discount_amount', 'i.net_amount'], 'page', $page);

        return [
            'rows' => collect($paginator->items())->map(fn ($r) => [
                'id' => $r->id,
                'reference' => $r->reference,
                'occurred_at' => CarbonImmutable::parse($r->occurred_at, 'UTC')->setTimezone($timezone)->toIso8601String(),
                'channel' => $r->channel,
                'sku' => $r->sku,
                'product' => $r->product,
                'quantity' => (float) $r->quantity,
                'unit_price' => (float) $r->unit_price,
                'discount' => (float) $r->discount_amount,
                'amount' => (float) $r->net_amount,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
