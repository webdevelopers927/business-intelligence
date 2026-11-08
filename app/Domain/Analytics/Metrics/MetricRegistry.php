<?php

namespace App\Domain\Analytics\Metrics;

use App\Domain\Metrics\Formula\Formula;
use App\Domain\Metrics\Formula\FormulaException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Metric;
use InvalidArgumentException;

/**
 * Every metric the engine can compute.
 *
 * Base metrics come from two fact aggregates —
 *   sales    → revenue, cogs, units_sold, orders   (completed transactions only)
 *   expenses → operating_expenses
 * — and fixed derivations. Custom metrics are formulas over other metrics,
 * evaluated in dependency order on top of the base values. All screens use
 * this registry, so they agree by construction.
 */
final class MetricRegistry
{
    /** Dimensions each fact can be grouped or filtered by. */
    public const FACT_DIMENSIONS = [
        'sales' => ['date', 'branch', 'category', 'product', 'channel'],
        'expenses' => ['date', 'branch', 'department', 'expense_category'],
    ];

    /** @var array<string, MetricDefinition>|null */
    private ?array $base = null;

    /** @var array<string, MetricDefinition>|null Custom metrics in dependency (evaluation) order. */
    private ?array $custom = null;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array<string, MetricDefinition> */
    public function base(): array
    {
        return $this->base ??= collect([
            new MetricDefinition('revenue', 'Revenue', MetricDefinition::CURRENCY, 'up_good', ['sales'], 'Net sales of completed transactions after line discounts.'),
            new MetricDefinition('cogs', 'Cost of goods sold', MetricDefinition::CURRENCY, 'up_bad', ['sales'], 'Units sold × unit cost captured at the time of sale.'),
            new MetricDefinition('gross_profit', 'Gross profit', MetricDefinition::CURRENCY, 'up_good', ['sales'], 'Revenue − cost of goods sold.'),
            new MetricDefinition('gross_margin', 'Gross margin', MetricDefinition::PERCENT, 'up_good', ['sales'], 'Gross profit ÷ revenue.'),
            new MetricDefinition('operating_expenses', 'Operating expenses', MetricDefinition::CURRENCY, 'up_bad', ['expenses'], 'All recorded operating costs.'),
            new MetricDefinition('net_profit', 'Net profit', MetricDefinition::CURRENCY, 'up_good', ['sales', 'expenses'], 'Gross profit − operating expenses.'),
            new MetricDefinition('profit_margin', 'Net margin', MetricDefinition::PERCENT, 'up_good', ['sales', 'expenses'], 'Net profit ÷ revenue.'),
            new MetricDefinition('expense_ratio', 'Expense ratio', MetricDefinition::PERCENT, 'up_bad', ['sales', 'expenses'], 'Operating expenses ÷ revenue.'),
            new MetricDefinition('orders', 'Orders', MetricDefinition::NUMBER, 'up_good', ['sales'], 'Completed transactions.'),
            new MetricDefinition('average_order_value', 'Average order value', MetricDefinition::CURRENCY, 'up_good', ['sales'], 'Revenue ÷ orders.'),
            new MetricDefinition('units_sold', 'Units sold', MetricDefinition::NUMBER, 'up_good', ['sales'], 'Quantity across completed transactions.'),
        ])->keyBy('key')->all();
    }

    /** @return array<string, MetricDefinition> Custom metrics of the current organization, in evaluation order. */
    public function custom(): array
    {
        if ($this->custom !== null) {
            return $this->custom;
        }

        if (! $this->tenant->has()) {
            return $this->custom = [];
        }

        $rows = Metric::query()->orderBy('id')->get()->keyBy('key');
        $resolved = [];
        $visiting = [];

        $resolve = function (string $key) use (&$resolve, &$resolved, &$visiting, $rows): ?array {
            if (isset($this->base()[$key])) {
                return $this->base()[$key]->facts;
            }
            if (array_key_exists($key, $resolved)) {
                return $resolved[$key]?->facts;
            }
            $row = $rows[$key] ?? null;
            if ($row === null || isset($visiting[$key])) {
                return null; // unknown or cyclic: the metric is unusable
            }

            $visiting[$key] = true;
            try {
                $formula = Formula::parse($row->formula);
                $facts = [];
                foreach ($formula->identifiers() as $dependency) {
                    $depFacts = $resolve($dependency);
                    if ($depFacts === null) {
                        return $resolved[$key] = null;
                    }
                    $facts = [...$facts, ...$depFacts];
                }
            } catch (FormulaException) {
                return $resolved[$key] = null;
            } finally {
                unset($visiting[$key]);
            }

            $resolved[$key] = new MetricDefinition(
                $row->key,
                $row->name,
                $row->format,
                $row->polarity,
                array_values(array_unique($facts)),
                $row->description ?? 'Custom metric: '.$formula->normalized(),
                $formula,
                $row->decimals,
            );

            return $resolved[$key]->facts;
        };

        foreach ($rows->keys() as $key) {
            $resolve((string) $key);
        }

        // $resolved fills in dependency order (dependencies resolve first).
        return $this->custom = array_filter($resolved);
    }

    /** @return array<string, MetricDefinition> */
    public function all(): array
    {
        return [...$this->base(), ...$this->custom()];
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): MetricDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown metric [{$key}].");
    }

    /** Forget cached custom definitions (after a metric is created/changed). */
    public function refresh(): void
    {
        $this->custom = null;
    }

    public function supports(string $metric, string $dimension): bool
    {
        foreach ($this->get($metric)->facts as $fact) {
            if (! in_array($dimension, self::FACT_DIMENSIONS[$fact], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Base derivations plus every custom metric evaluated on top.
     *
     * @param  array{revenue?: float, cogs?: float, units_sold?: float, orders?: float, operating_expenses?: float}  $raw
     * @return array<string, float|null>
     */
    public function derive(array $raw): array
    {
        $values = self::deriveBase($raw);

        foreach ($this->custom() as $key => $definition) {
            $result = $definition->formula?->evaluate($values);
            $values[$key] = $result === null ? null : round($result, 4);
        }

        return $values;
    }

    /**
     * Ratios are null when the denominator is zero or missing — never ±∞ or NaN.
     *
     * @param  array{revenue?: float, cogs?: float, units_sold?: float, orders?: float, operating_expenses?: float}  $raw
     * @return array<string, float|null>
     */
    public static function deriveBase(array $raw): array
    {
        $revenue = (float) ($raw['revenue'] ?? 0);
        $cogs = (float) ($raw['cogs'] ?? 0);
        $orders = (float) ($raw['orders'] ?? 0);
        $opex = (float) ($raw['operating_expenses'] ?? 0);
        $gross = $revenue - $cogs;
        $net = $gross - $opex;

        return [
            'revenue' => round($revenue, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($gross, 2),
            'gross_margin' => self::ratio($gross, $revenue),
            'operating_expenses' => round($opex, 2),
            'net_profit' => round($net, 2),
            'profit_margin' => self::ratio($net, $revenue),
            'expense_ratio' => self::ratio($opex, $revenue),
            'orders' => $orders,
            'average_order_value' => $orders > 0 ? round($revenue / $orders, 2) : null,
            'units_sold' => (float) ($raw['units_sold'] ?? 0),
        ];
    }

    /** Percentage change, null when there is no meaningful base. */
    public static function change(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || abs($previous) < 0.000001) {
            return null;
        }

        return round(($current - $previous) / abs($previous) * 100, 2);
    }

    private static function ratio(float $numerator, float $denominator): ?float
    {
        return abs($denominator) < 0.000001 ? null : round($numerator / $denominator * 100, 2);
    }
}
