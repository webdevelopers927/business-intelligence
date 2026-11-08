<?php

namespace App\Domain\Dashboards;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Http\Support\AnalyticsQuery;
use App\Models\DashboardWidget;
use InvalidArgumentException;

/**
 * Resolves a widget's data through the analytics engine.
 *
 * Range: "inherit" follows the dashboard's date control; "fixed" pins a preset.
 * Filters: widget-level filters, when set, replace the dashboard's for that
 * dimension; otherwise the dashboard filters apply.
 */
final class WidgetDataService
{
    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly MetricRegistry $metrics,
    ) {}

    /** @return array<string, mixed> */
    public function data(DashboardWidget $widget, AnalyticsQuery $dashboard): array
    {
        $config = $widget->config;
        $metricKey = (string) ($config['metric'] ?? '');

        if (! $this->metrics->has($metricKey)) {
            throw new InvalidArgumentException('This widget uses a metric that no longer exists. Edit the widget to choose another.');
        }

        $metric = $this->metrics->get($metricKey);
        $range = ($config['range']['mode'] ?? 'inherit') === 'fixed' && isset($config['range']['preset'])
            ? DateRange::fromPreset($config['range']['preset'], $dashboard->today)
            : $dashboard->range;

        $filters = new Filters(
            branchIds: ($config['filters']['branches'] ?? []) ?: $dashboard->filters->branchIds,
            categoryIds: ($config['filters']['categories'] ?? []) ?: $dashboard->filters->categoryIds,
        );

        $dimension = $config['dimension'] ?? null;
        $compare = (bool) ($config['comparison'] ?? false);
        $previous = $range->previous();

        $base = [
            'metric' => $metric->toArray(),
            'period' => ['label' => $range->label(), 'previous_label' => $previous->label(), 'fixed' => ($config['range']['mode'] ?? 'inherit') === 'fixed'],
            'ignored_filters' => $this->engine->ignoredFilters($metricKey, $filters),
        ];

        if ($widget->type === 'kpi') {
            $kpi = $this->engine->kpis($range, $filters, [$metricKey])[$metricKey];
            $spark = $this->engine->series($range, $filters, [$metricKey]);

            return [...$base, 'kind' => 'kpi', 'kpi' => $kpi, 'spark' => $spark['series'][$metricKey]];
        }

        if ($dimension === 'date') {
            return [...$base, 'kind' => 'series', 'series' => $this->engine->series($range, $filters, [$metricKey], compare: $compare)];
        }

        $rows = $this->engine->breakdown(
            $range,
            $filters,
            (string) $dimension,
            [$metricKey],
            limit: (int) ($config['limit'] ?? 8),
            orderBy: $metricKey,
            compare: $compare,
        );

        // A widget breaks down by its own metric; "Head office" only appears for expense metrics.
        return [...$base, 'kind' => 'breakdown', 'dimension' => $dimension, 'rows' => $rows];
    }
}
