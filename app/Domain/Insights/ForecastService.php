<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Monthly forecasts for the current organization, built from complete months
 * only (the running month is excluded so a partial month never drags the
 * trend down). Results are estimates and are always returned with their method.
 */
final class ForecastService
{
    /** Additive amounts and counts; ratios (margins) aren't forecast by trend. */
    public const METRICS = ['revenue', 'gross_profit', 'net_profit', 'operating_expenses', 'orders', 'units_sold'];

    public const NON_NEGATIVE = ['revenue', 'operating_expenses', 'orders', 'units_sold'];

    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly MetricRegistry $metrics,
        private readonly TenantContext $tenant,
        private readonly Forecaster $forecaster,
    ) {}

    /**
     * @return array{
     *   metric: string, label: string, format: string, estimate: true,
     *   history: list<array{key: string, label: string, value: float}>,
     *   forecast: list<array{key: string, label: string, value: float, lower: float, upper: float}>,
     *   method: string|null, method_label: string|null, backtest_mape: float|null,
     *   methods: list<array{value: string, label: string, available: bool}>,
     *   months: int, message: string|null
     * }
     */
    public function forecast(string $metric, int $horizon = 6, string $method = 'auto', ?Filters $filters = null, ?CarbonImmutable $now = null): array
    {
        if (! in_array($metric, self::METRICS, true)) {
            throw new InvalidArgumentException('Forecasts are available for: '.implode(', ', self::METRICS).'.');
        }
        if ($method !== 'auto' && ! isset(Forecaster::METHODS[$method])) {
            throw new InvalidArgumentException("Unknown forecast method [{$method}].");
        }
        $horizon = max(1, min(12, $horizon));
        $definition = $this->metrics->get($metric);
        $today = ($now ?? CarbonImmutable::now())->setTimezone($this->tenant->organization()->timezone)->startOfDay();
        $lastMonthEnd = $today->startOfMonth()->subDay();

        $history = [];
        $start = Coverage::start($this->tenant->id(), $definition);
        $first = $start === null ? null : CarbonImmutable::parse($start)->startOfMonth();
        if ($first !== null && $first->lte($lastMonthEnd)) {
            // At most 36 months back: older seasons say little about next quarter.
            $from = $first->max($lastMonthEnd->startOfMonth()->subMonths(35));
            $series = $this->engine->series(DateRange::custom($from, $lastMonthEnd), $filters ?? new Filters, [$metric], Granularity::Month);
            foreach ($series['keys'] as $i => $key) {
                $history[] = ['key' => $key, 'label' => $series['labels'][$i], 'value' => (float) ($series['series'][$metric][$i] ?? 0)];
            }
        }

        $values = array_column($history, 'value');
        $methods = array_map(fn ($k, $label) => ['value' => $k, 'label' => $label, 'available' => Forecaster::available($k, $values)], array_keys(Forecaster::METHODS), Forecaster::METHODS);
        $base = [
            'metric' => $metric, 'label' => $definition->label, 'format' => $definition->format, 'estimate' => true,
            'history' => $history, 'forecast' => [], 'method' => null, 'method_label' => null, 'backtest_mape' => null,
            'methods' => $methods, 'months' => count($values), 'message' => null,
        ];

        if (count($values) < Forecaster::MIN_HISTORY) {
            return [...$base, 'message' => sprintf('Forecasts need at least %d complete months of data; %d available.', Forecaster::MIN_HISTORY, count($values))];
        }

        try {
            $result = $this->forecaster->forecast($values, $horizon, $method);
        } catch (InvalidArgumentException $e) {
            return [...$base, 'message' => $e->getMessage()];
        }

        // Quantities that can't be negative by definition are floored at zero;
        // profit can be, so its range is left unclipped.
        $floor = in_array($metric, self::NON_NEGATIVE, true) ? 0.0 : -INF;
        $forecast = [];
        $month = $lastMonthEnd->startOfMonth();
        foreach ($result['points'] as $i => $point) {
            $month = $month->addMonth();
            $forecast[] = [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'value' => round(max($floor, $point), 2),
                'lower' => round(max($floor, $result['lower'][$i]), 2),
                'upper' => round(max($floor, $result['upper'][$i]), 2),
            ];
        }

        return [...$base, 'forecast' => $forecast, 'method' => $result['method'], 'method_label' => $result['label'], 'backtest_mape' => $result['backtest_mape']];
    }
}
