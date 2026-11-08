<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single source of analytics truth (docs/ARCHITECTURE.md §7).
 *
 * Every screen — executive dashboard, explorer, insights, and later widgets,
 * reports, alerts and the AI analyst — calls these methods, so numbers that
 * appear side by side cannot disagree.
 *
 * Queries always start from the tenant id; filter ids are applied inside that
 * scope, so a foreign id simply matches nothing.
 */
final class AnalyticsEngine
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AnalyticsCache $cache,
        private readonly MetricRegistry $metrics,
    ) {}

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Totals for the range plus the comparison period.
     *
     * @param  list<string>  $metrics
     * @return array<string, array{key: string, label: string, format: string, polarity: string, value: float|null, previous: float|null, change: float|null, change_unit: 'percent'|'pts'}>
     */
    public function kpis(DateRange $range, Filters $filters, array $metrics): array
    {
        $current = $this->totals($range, $filters);
        $previous = $this->totals($range->previous(), $filters);

        $out = [];
        foreach ($metrics as $key) {
            $def = $this->metrics->get($key);
            // Ratios change in percentage points; "+437% net margin" would mislead.
            $isRatio = $def->format === 'percent';
            $out[$key] = [
                ...$def->toArray(),
                'value' => $current[$key],
                'previous' => $previous[$key],
                'change' => $isRatio
                    ? ($current[$key] === null || $previous[$key] === null ? null : round($current[$key] - $previous[$key], 2))
                    : MetricRegistry::change($current[$key], $previous[$key]),
                'change_unit' => $isRatio ? 'pts' : 'percent',
            ];
        }

        return $out;
    }

    /**
     * All derived metrics for a range.
     *
     * @return array<string, float|null>
     */
    public function totals(DateRange $range, Filters $filters): array
    {
        $sales = $this->salesAggregate($range, $filters)[''] ?? [];
        $expenses = $this->expenseAggregate($range, $filters)[''] ?? [];

        return $this->metrics->derive([...$sales, ...$expenses]);
    }

    /**
     * Time series, gap-filled. With $compare, the previous period is aligned
     * by position (bucket 1 vs bucket 1), which is how "vs previous period"
     * lines are read.
     *
     * @param  list<string>  $metrics
     * @return array{granularity: string, keys: list<string>, labels: list<string>, series: array<string, list<float|null>>, previous: array<string, list<float|null>>, previous_labels: list<string>}
     */
    public function series(DateRange $range, Filters $filters, array $metrics, ?Granularity $granularity = null, bool $compare = false): array
    {
        $granularity ??= $range->granularity();
        $build = function (DateRange $r) use ($filters, $metrics, $granularity) {
            $keys = $granularity->keysBetween($r->from, $r->to);
            $sales = $this->needs($metrics, 'sales') ? $this->salesAggregate($r, $filters, 'date', $granularity) : [];
            $expenses = $this->needs($metrics, 'expenses') ? $this->expenseAggregate($r, $filters, 'date', $granularity) : [];

            $series = array_fill_keys($metrics, []);
            foreach ($keys as $key) {
                $derived = $this->metrics->derive([...($sales[$key] ?? []), ...($expenses[$key] ?? [])]);
                foreach ($metrics as $metric) {
                    $series[$metric][] = $derived[$metric];
                }
            }

            return [$keys, $series];
        };

        [$keys, $series] = $build($range);
        $result = [
            'granularity' => $granularity->value,
            'keys' => $keys,
            'labels' => array_map(fn ($k) => $granularity->label($k), $keys),
            'series' => $series,
            'previous' => [],
            'previous_labels' => [],
        ];

        if ($compare) {
            [$prevKeys, $prevSeries] = $build($range->previous());
            $result['previous'] = $prevSeries;
            $result['previous_labels'] = array_map(fn ($k) => $granularity->label($k), $prevKeys);
        }

        return $result;
    }

    /**
     * Metrics grouped by a dimension, with optional comparison.
     *
     * @param  list<string>  $metrics
     * @return list<array{id: int|string, label: string, color_index: int|null, values: array<string, float|null>, previous: array<string, float|null>|null}>
     */
    public function breakdown(DateRange $range, Filters $filters, string $dimension, array $metrics, ?int $limit = null, string $orderBy = 'revenue', bool $compare = false): array
    {
        foreach ($metrics as $metric) {
            if (! $this->metrics->supports($metric, $dimension)) {
                throw new InvalidArgumentException("Metric [{$metric}] can't be broken down by [{$dimension}].");
            }
        }

        $fetch = function (DateRange $r) use ($filters, $dimension, $metrics) {
            $sales = $this->needs($metrics, 'sales') ? $this->salesAggregate($r, $filters, $dimension) : [];
            $expenses = $this->needs($metrics, 'expenses') ? $this->expenseAggregate($r, $filters, $dimension) : [];
            $rows = [];
            foreach (array_unique([...array_keys($sales), ...array_keys($expenses)]) as $key) {
                $rows[(string) $key] = $this->metrics->derive([...($sales[$key] ?? []), ...($expenses[$key] ?? [])]);
            }

            return $rows;
        };

        $current = $fetch($range);
        $previous = $compare ? $fetch($range->previous()) : [];

        uasort($current, fn ($a, $b) => ($b[$orderBy] ?? 0) <=> ($a[$orderBy] ?? 0));
        if ($limit !== null) {
            $current = array_slice($current, 0, $limit, true);
        }

        $labels = $this->labels($dimension, array_keys($current));

        return array_map(function (string $key) use ($current, $previous, $labels, $metrics, $compare) {
            $pick = fn (?array $values) => $values === null ? null : array_intersect_key($values, array_flip($metrics));

            return [
                'id' => is_numeric($key) ? (int) $key : $key,
                'label' => $labels[$key]['label'] ?? ($key === '' ? 'Unassigned' : $key),
                'color_index' => $labels[$key]['color_index'] ?? null,
                'values' => $pick($current[$key]),
                'previous' => $compare ? $pick($previous[$key] ?? $this->metrics->derive([])) : null,
            ];
        }, array_map('strval', array_keys($current)));
    }

    /**
     * Prorated revenue targets per branch for the range (null branch = organization).
     *
     * @return array<string, float> branch id ('' for organization) => target
     */
    public function targets(DateRange $range): array
    {
        $rows = DB::table('revenue_targets')
            ->where('organization_id', $this->tenant->id())
            ->whereBetween('month', [$range->from->startOfMonth()->toDateString(), $range->to->toDateString()])
            ->get(['branch_id', 'month', 'amount']);

        $out = [];
        foreach ($rows as $row) {
            $monthStart = CarbonImmutable::parse($row->month)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $from = $range->from->gt($monthStart) ? $range->from : $monthStart;
            $to = $range->to->lt($monthEnd) ? $range->to : $monthEnd;
            if ($to->lt($from)) {
                continue;
            }
            $share = ($from->diffInDays($to) + 1) / ($monthStart->diffInDays($monthEnd) + 1);
            $key = $row->branch_id === null ? '' : (string) $row->branch_id;
            $out[$key] = ($out[$key] ?? 0) + (float) $row->amount * $share;
        }

        return $out;
    }

    /**
     * Stock position from the latest snapshot on or before $asOf, per product and branch.
     *
     * @return array{value: float, low_stock: int, lines: int, as_of: string|null}
     */
    public function inventory(CarbonImmutable $asOf, Filters $filters): array
    {
        return $this->cache->remember($this->tenant->id(), "inventory|{$asOf->toDateString()}|{$filters->fingerprint()}", $asOf->isToday(), function () use ($asOf, $filters) {
            $query = $this->latestInventory($asOf, $filters);
            $row = (clone $query)->selectRaw('COALESCE(SUM(r.quantity_on_hand * r.unit_cost), 0) AS value, COUNT(*) AS lines, SUM(CASE WHEN r.quantity_on_hand <= r.reorder_level THEN 1 ELSE 0 END) AS low_stock, MAX(r.recorded_on) AS as_of')->first();

            return [
                'value' => round((float) ($row->value ?? 0), 2),
                'low_stock' => (int) ($row->low_stock ?? 0),
                'lines' => (int) ($row->lines ?? 0),
                'as_of' => $row->as_of ?? null,
            ];
        });
    }

    /**
     * Products at or below their reorder level in the latest snapshot.
     *
     * @return list<array{sku: string, product: string, branch: string, on_hand: float, reorder_level: float}>
     */
    public function lowStock(CarbonImmutable $asOf, Filters $filters, int $limit = 8): array
    {
        return $this->latestInventory($asOf, $filters)
            ->whereColumn('r.quantity_on_hand', '<=', 'r.reorder_level')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->orderByRaw('r.quantity_on_hand - r.reorder_level')
            ->limit($limit)
            ->get(['p.sku', 'p.name as product', 'b.name as branch', 'r.quantity_on_hand as on_hand', 'r.reorder_level'])
            ->map(fn ($r) => ['sku' => $r->sku, 'product' => $r->product, 'branch' => $r->branch, 'on_hand' => (float) $r->on_hand, 'reorder_level' => (float) $r->reorder_level])
            ->all();
    }

    /**
     * Employees on the books at the end of the day.
     *
     * @return array{total: int, by_department: list<array{label: string, value: int}>}
     */
    public function headcount(CarbonImmutable $asOf, Filters $filters): array
    {
        $rows = DB::table('employees as e')
            ->join('departments as d', 'd.id', '=', 'e.department_id')
            ->where('e.organization_id', $this->tenant->id())
            ->whereNull('e.deleted_at')
            ->where('e.hired_on', '<=', $asOf->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('e.terminated_on')->orWhere('e.terminated_on', '>', $asOf->toDateString()))
            ->when($filters->branchIds !== [], fn (Builder $q) => $q->whereIn('e.branch_id', $filters->branchIds))
            ->when($filters->departmentIds !== [], fn (Builder $q) => $q->whereIn('e.department_id', $filters->departmentIds))
            ->groupBy('d.name')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw('d.name AS label, COUNT(*) AS value')
            ->get();

        return [
            'total' => $rows->reduce(fn (int $sum, $r) => $sum + (int) $r->value, 0),
            'by_department' => $rows->map(fn ($r) => ['label' => $r->label, 'value' => (int) $r->value])->all(),
        ];
    }

    /** Whether any completed sales exist for the organization. */
    public function hasData(): bool
    {
        return DB::table('sales_transactions')->where('organization_id', $this->tenant->id())->exists();
    }

    /** Latest business date with sales (for "data through" labels). */
    public function lastSalesDate(): ?string
    {
        return DB::table('sales_transactions')->where('organization_id', $this->tenant->id())->max('business_date');
    }

    /**
     * Which filters don't apply to a metric (e.g. product category has no
     * meaning for expenses). Shown in the UI instead of silently ignoring.
     *
     * @return list<string>
     */
    public function ignoredFilters(string $metric, Filters $filters): array
    {
        $ignored = [];
        foreach (['category' => $filters->categoryIds, 'department' => $filters->departmentIds] as $dimension => $ids) {
            if ($ids !== [] && ! $this->metrics->supports($metric, $dimension)) {
                $ignored[] = $dimension;
            }
        }

        return $ignored;
    }

    // ── Fact aggregates ─────────────────────────────────────────────────

    /**
     * Sales aggregate grouped by a dimension ('' key when ungrouped).
     *
     * @return array<string, array{revenue: float, cogs: float, units_sold: float, orders: float}>
     */
    private function salesAggregate(DateRange $range, Filters $filters, ?string $dimension = null, ?Granularity $granularity = null): array
    {
        $fingerprint = "sales|{$range->from->toDateString()}|{$range->to->toDateString()}|{$dimension}|{$granularity?->value}|{$filters->fingerprint()}";

        return $this->cache->remember($this->tenant->id(), $fingerprint, $range->to->isToday(), function () use ($range, $filters, $dimension, $granularity) {
            $needsProduct = $dimension === 'category' || $filters->categoryIds !== [];

            $group = match ($dimension) {
                null => "''",
                'date' => TimeBucket::expression($granularity ?? Granularity::Day, 'i.business_date'),
                'branch' => 'i.branch_id',
                'category' => 'p.product_category_id',
                'product' => 'i.product_id',
                'channel' => 't.channel',
                default => throw new InvalidArgumentException("Sales can't be grouped by [{$dimension}]."),
            };

            $rows = DB::table('sales_transaction_items as i')
                ->join('sales_transactions as t', 't.id', '=', 'i.sales_transaction_id')
                ->when($needsProduct, fn (Builder $q) => $q->join('products as p', 'p.id', '=', 'i.product_id'))
                ->where('i.organization_id', $this->tenant->id())
                ->where('t.status', 'completed')
                ->whereBetween('i.business_date', [$range->from->toDateString(), $range->to->toDateString()])
                ->when($filters->branchIds !== [], fn (Builder $q) => $q->whereIn('i.branch_id', $filters->branchIds))
                ->when($filters->categoryIds !== [], fn (Builder $q) => $q->whereIn('p.product_category_id', $filters->categoryIds))
                ->selectRaw("{$group} AS k, SUM(i.net_amount) AS revenue, SUM(i.quantity * i.unit_cost) AS cogs, SUM(i.quantity) AS units_sold, COUNT(DISTINCT i.sales_transaction_id) AS orders")
                ->when($dimension !== null, fn (Builder $q) => $q->groupByRaw($group))
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[(string) $row->k] = [
                    'revenue' => (float) $row->revenue,
                    'cogs' => (float) $row->cogs,
                    'units_sold' => (float) $row->units_sold,
                    'orders' => (float) $row->orders,
                ];
            }

            return $out;
        });
    }

    /**
     * Expense aggregate. A branch filter keeps only costs attributed to those
     * branches (head-office costs have no branch); product-category filters
     * do not apply to expenses (reported via ignoredFilters()).
     *
     * @return array<string, array{operating_expenses: float}>
     */
    private function expenseAggregate(DateRange $range, Filters $filters, ?string $dimension = null, ?Granularity $granularity = null): array
    {
        $fingerprint = "expenses|{$range->from->toDateString()}|{$range->to->toDateString()}|{$dimension}|{$granularity?->value}|{$filters->fingerprint()}";

        return $this->cache->remember($this->tenant->id(), $fingerprint, $range->to->isToday(), function () use ($range, $filters, $dimension, $granularity) {
            $group = match ($dimension) {
                null => "''",
                'date' => TimeBucket::expression($granularity ?? Granularity::Day, 'e.business_date'),
                'branch' => 'e.branch_id',
                'department' => 'e.department_id',
                'expense_category' => 'e.expense_category_id',
                default => throw new InvalidArgumentException("Expenses can't be grouped by [{$dimension}]."),
            };

            $rows = DB::table('expenses as e')
                ->where('e.organization_id', $this->tenant->id())
                ->whereBetween('e.business_date', [$range->from->toDateString(), $range->to->toDateString()])
                ->when($filters->branchIds !== [], fn (Builder $q) => $q->whereIn('e.branch_id', $filters->branchIds))
                ->when($filters->departmentIds !== [], fn (Builder $q) => $q->whereIn('e.department_id', $filters->departmentIds))
                ->selectRaw("{$group} AS k, SUM(e.amount) AS operating_expenses")
                ->when($dimension !== null, fn (Builder $q) => $q->groupByRaw($group))
                ->get();

            $out = [];
            foreach ($rows as $row) {
                $out[(string) ($row->k ?? '')] = ['operating_expenses' => (float) $row->operating_expenses];
            }

            return $out;
        });
    }

    private function latestInventory(CarbonImmutable $asOf, Filters $filters): Builder
    {
        $org = $this->tenant->id();

        return DB::table('inventory_records as r')
            ->where('r.organization_id', $org)
            ->whereRaw('r.recorded_on = (SELECT MAX(r2.recorded_on) FROM inventory_records r2 WHERE r2.organization_id = r.organization_id AND r2.product_id = r.product_id AND r2.branch_id = r.branch_id AND r2.recorded_on <= ?)', [$asOf->toDateString()])
            ->when($filters->branchIds !== [], fn (Builder $q) => $q->whereIn('r.branch_id', $filters->branchIds))
            ->when($filters->categoryIds !== [], fn (Builder $q) => $q->whereIn('r.product_id', DB::table('products')->select('id')->where('organization_id', $org)->whereIn('product_category_id', $filters->categoryIds)));
    }

    /** @param list<string> $metrics */
    private function needs(array $metrics, string $fact): bool
    {
        foreach ($metrics as $metric) {
            if (in_array($fact, $this->metrics->get($metric)->facts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display labels and stable color slots for dimension keys. The color
     * slot follows the entity's position among *all* the organization's
     * entities (not its rank in this result), so a branch keeps its color
     * when filters change (DESIGN_SYSTEM §3).
     *
     * @param  list<int|string>  $keys
     * @return array<string, array{label: string, color_index: int|null}>
     */
    private function labels(string $dimension, array $keys): array
    {
        $table = match ($dimension) {
            'branch' => 'branches',
            'category' => 'product_categories',
            'product' => 'products',
            'department' => 'departments',
            'expense_category' => 'expense_categories',
            default => null,
        };

        if ($table === null) {
            return array_combine(
                array_map('strval', $keys),
                array_map(fn ($k) => ['label' => ucfirst((string) $k), 'color_index' => null], $keys),
            ) ?: [];
        }

        $columns = $table === 'products' ? ['id', 'name', 'sku'] : ['id', 'name'];
        $all = DB::table($table)->where('organization_id', $this->tenant->id())->orderBy('id')->get($columns);

        $out = [];
        foreach ($all as $index => $row) {
            $out[(string) $row->id] = [
                'label' => $row->name,
                'color_index' => $table === 'products' ? null : $index,
            ];
        }
        $out[''] = ['label' => $dimension === 'branch' ? 'Head office' : 'Unassigned', 'color_index' => null];

        return $out;
    }
}
