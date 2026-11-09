<?php

namespace App\Domain\Ai;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Insights\AnomalyService;
use App\Domain\Insights\Forecaster;
use App\Domain\Insights\ForecastService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Anomaly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The only way the AI analyst can see data (docs/ARCHITECTURE.md §11).
 *
 * Every tool is read-only, validates its arguments strictly, and runs through
 * AnalyticsEngine / Insights inside the requester's tenant context — so the
 * model can never reach another organization's data or issue SQL. Each
 * successful call also yields an Evidence item rendered in the UI from the
 * tool output itself (not from the model's prose).
 */
final class AnalystTools
{
    public const MAX_SPAN_DAYS = 1100;

    public const DIMENSIONS = ['branch', 'category', 'product', 'channel', 'department', 'expense_category'];

    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly MetricRegistry $metrics,
        private readonly TenantContext $tenant,
        private readonly ForecastService $forecasts,
    ) {}

    /**
     * Tool definitions in API wire format. Deterministic order and content so
     * the prompt-cache prefix stays stable.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $period = [
            'from' => ['type' => 'string', 'description' => 'Start date, YYYY-MM-DD (inclusive).'],
            'to' => ['type' => 'string', 'description' => 'End date, YYYY-MM-DD (inclusive).'],
        ];
        $filters = [
            'branch_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional branch ids to restrict to.'],
            'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional product category ids to restrict to (sales metrics only).'],
        ];
        $metric = ['type' => 'string', 'description' => 'Metric key from the catalog in the system prompt.'];

        return [
            [
                'name' => 'get_metrics',
                'description' => 'Totals of up to 6 metrics for one period, optionally filtered by branch or category. Use for "what was X" questions.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['metrics', 'from', 'to'], 'properties' => [
                    'metrics' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 6, 'description' => 'Metric keys.'],
                    ...$period, ...$filters,
                ]],
            ],
            [
                'name' => 'compare_periods',
                'description' => 'Compare metrics between two periods (default comparison: the immediately preceding period of equal length). Returns both values and the change; ratio metrics change in percentage points.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['metrics', 'from', 'to'], 'properties' => [
                    'metrics' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => 6],
                    ...$period,
                    'compare_from' => ['type' => 'string', 'description' => 'Optional comparison start, YYYY-MM-DD.'],
                    'compare_to' => ['type' => 'string', 'description' => 'Optional comparison end, YYYY-MM-DD.'],
                    ...$filters,
                ]],
            ],
            [
                'name' => 'breakdown',
                'description' => 'One metric split by a dimension for a period, with each item\'s share of the total and change versus the previous period. Use order "asc" to find the weakest items.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['metric', 'dimension', 'from', 'to'], 'properties' => [
                    'metric' => $metric,
                    'dimension' => ['type' => 'string', 'enum' => self::DIMENSIONS],
                    ...$period,
                    'order' => ['type' => 'string', 'enum' => ['desc', 'asc'], 'description' => 'Sort by the metric; default desc.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'Default 10.'],
                    ...$filters,
                ]],
            ],
            [
                'name' => 'trend',
                'description' => 'A metric over time for a period, bucketed by day, week or month.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['metric', 'from', 'to'], 'properties' => [
                    'metric' => $metric,
                    ...$period,
                    'granularity' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Default chosen from the span.'],
                    ...$filters,
                ]],
            ],
            [
                'name' => 'list_anomalies',
                'description' => 'Detected anomalies (unusual days for revenue and orders; unusual months for operating expenses) from the last 90 days, with expected ranges and explanations.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'metric' => ['type' => 'string', 'enum' => array_keys(AnomalyService::METRICS)],
                    'severity' => ['type' => 'string', 'enum' => ['warning', 'critical']],
                    'from' => ['type' => 'string', 'description' => 'Optional, YYYY-MM-DD.'],
                    'to' => ['type' => 'string', 'description' => 'Optional, YYYY-MM-DD.'],
                ]],
            ],
            [
                'name' => 'forecast',
                'description' => 'Statistical monthly forecast (an ESTIMATE, not data) for the next months, with a 95% range and the method used. Only for future months.',
                'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['metric'], 'properties' => [
                    'metric' => ['type' => 'string', 'enum' => ForecastService::METRICS],
                    'horizon' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Months ahead; default 3.'],
                    'method' => ['type' => 'string', 'enum' => ['auto', ...array_keys(Forecaster::METHODS)]],
                ]],
            ],
        ];
    }

    /**
     * Run a tool. Never throws for bad input: errors come back as
     * ['error' => message] so the model can correct itself.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, result: array<string, mixed>, evidence: array<string, mixed>|null}
     */
    public function run(string $name, array $input): array
    {
        try {
            [$result, $evidence] = match ($name) {
                'get_metrics' => $this->getMetrics($input),
                'compare_periods' => $this->comparePeriods($input),
                'breakdown' => $this->breakdown($input),
                'trend' => $this->trend($input),
                'list_anomalies' => $this->listAnomalies($input),
                'forecast' => $this->forecast($input),
                default => throw new InvalidArgumentException("Unknown tool [{$name}]."),
            };

            return ['ok' => true, 'result' => $result, 'evidence' => $evidence];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'result' => ['error' => $e->getMessage()], 'evidence' => null];
        } catch (Throwable $e) {
            report($e);

            return ['ok' => false, 'result' => ['error' => 'The query failed. Try a narrower question.'], 'evidence' => null];
        }
    }

    // ---- Tools ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function getMetrics(array $in): array
    {
        $metrics = $this->metricList($in['metrics'] ?? null);
        $range = $this->range($in);
        $filters = $this->filters($in);
        $totals = $this->engine->totals($range, $filters);

        $rows = array_map(fn ($m) => ['metric' => $this->metrics->get($m)->label, 'key' => $m, 'value' => $totals[$m] ?? null, 'format' => $this->metrics->get($m)->format], $metrics);

        return [
            ['period' => $range->label(), 'filters' => $this->describeFilters($filters), 'currency' => $this->currency(), 'values' => array_map(fn ($r) => ['metric' => $r['key'], 'value' => $this->round($r['value'])], $rows), 'no_data' => $this->noData($totals)],
            $this->evidence('get_metrics', 'Totals', $range->label(), $filters, [['key' => 'metric', 'label' => 'Metric'], ['key' => 'value', 'label' => 'Value', 'format' => 'row']], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function comparePeriods(array $in): array
    {
        $metrics = $this->metricList($in['metrics'] ?? null);
        $range = $this->range($in);
        $other = isset($in['compare_from']) || isset($in['compare_to'])
            ? $this->range(['from' => $in['compare_from'] ?? null, 'to' => $in['compare_to'] ?? null])
            : $range->previous();
        $filters = $this->filters($in);
        $now = $this->engine->totals($range, $filters);
        $then = $this->engine->totals($other, $filters);

        $rows = [];
        foreach ($metrics as $m) {
            $def = $this->metrics->get($m);
            $ratio = $def->format === 'percent';
            $change = $ratio
                ? ($now[$m] === null || $then[$m] === null ? null : $now[$m] - $then[$m])
                : MetricRegistry::change($now[$m], $then[$m]);
            $rows[] = ['metric' => $def->label, 'key' => $m, 'format' => $def->format, 'current' => $now[$m] ?? null, 'previous' => $then[$m] ?? null, 'change' => $change === null ? null : round($change, 2), 'change_unit' => $ratio ? 'pts' : '%'];
        }

        return [
            ['period' => $range->label(), 'compared_with' => $other->label(), 'filters' => $this->describeFilters($filters), 'currency' => $this->currency(),
                'values' => array_map(fn ($r) => ['metric' => $r['key'], 'current' => $this->round($r['current']), 'previous' => $this->round($r['previous']), 'change' => $r['change'], 'change_unit' => $r['change_unit']], $rows)],
            $this->evidence('compare_periods', 'Period comparison', "{$range->label()} vs {$other->label()}", $filters, [
                ['key' => 'metric', 'label' => 'Metric'], ['key' => 'current', 'label' => $range->label(), 'format' => 'row'], ['key' => 'previous', 'label' => $other->label(), 'format' => 'row'], ['key' => 'change', 'label' => 'Change', 'format' => 'change'],
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function breakdown(array $in): array
    {
        $metric = $this->metric($in['metric'] ?? null);
        $dimension = (string) ($in['dimension'] ?? '');
        if (! in_array($dimension, self::DIMENSIONS, true)) {
            throw new InvalidArgumentException('dimension must be one of: '.implode(', ', self::DIMENSIONS).'.');
        }
        if (! $this->metrics->supports($metric, $dimension)) {
            throw new InvalidArgumentException("{$metric} can't be broken down by {$dimension} (sales metrics: branch, category, product, channel; expense metrics: branch, department, expense_category).");
        }
        $limit = $this->int($in['limit'] ?? 10, 1, 25, 'limit');
        $order = ($in['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $range = $this->range($in);
        $filters = $this->filters($in);
        $format = $this->metrics->get($metric)->format;

        $items = $this->engine->breakdown($range, $filters, $dimension, [$metric], null, $metric, compare: true);
        $total = array_sum(array_map(fn ($i) => (float) ($i['values'][$metric] ?? 0), $items));
        if ($order === 'asc') {
            $items = array_reverse($items);
        }
        $count = count($items);
        $items = array_slice($items, 0, $limit);

        $rows = array_map(fn ($i) => [
            'item' => $i['label'],
            'value' => $i['values'][$metric] ?? null,
            'share' => $total != 0.0 && $format !== 'percent' ? round((float) ($i['values'][$metric] ?? 0) / $total * 100, 1) : null,
            'change' => $format === 'percent'
                ? (($i['values'][$metric] ?? null) === null || ($i['previous'][$metric] ?? null) === null ? null : round($i['values'][$metric] - $i['previous'][$metric], 2))
                : MetricRegistry::change($i['values'][$metric] ?? null, $i['previous'][$metric] ?? null),
            'format' => $format,
        ], $items);

        return [
            ['metric' => $metric, 'dimension' => $dimension, 'period' => $range->label(), 'filters' => $this->describeFilters($filters), 'currency' => $this->currency(), 'order' => $order,
                'items_total' => $count, 'total' => $format === 'percent' ? null : $this->round($total),
                'items' => array_map(fn ($r) => ['item' => $r['item'], 'value' => $this->round($r['value']), 'share_pct' => $r['share'], 'change_vs_previous' => $r['change'] === null ? null : round($r['change'], 1)], $rows)],
            $this->evidence('breakdown', sprintf('%s by %s', $this->metrics->get($metric)->label, str_replace('_', ' ', $dimension)), $range->label(), $filters, [
                ['key' => 'item', 'label' => ucfirst(str_replace('_', ' ', $dimension))], ['key' => 'value', 'label' => $this->metrics->get($metric)->label, 'format' => $format], ['key' => 'share', 'label' => 'Share', 'format' => 'share'], ['key' => 'change', 'label' => 'vs previous', 'format' => $format === 'percent' ? 'pts' : 'change'],
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function trend(array $in): array
    {
        $metric = $this->metric($in['metric'] ?? null);
        $range = $this->range($in);
        $granularity = isset($in['granularity']) ? (Granularity::tryFrom((string) $in['granularity']) ?? throw new InvalidArgumentException('granularity must be day, week or month.')) : $range->granularity();
        if ($granularity === Granularity::Day && $range->days() > 120) {
            throw new InvalidArgumentException('Daily trends are limited to 120 days; use week or month for longer periods.');
        }
        $filters = $this->filters($in);
        $s = $this->engine->series($range, $filters, [$metric], $granularity);
        $format = $this->metrics->get($metric)->format;
        $rows = array_map(fn ($label, $v) => ['period' => $label, 'value' => $v, 'format' => $format], $s['labels'], $s['series'][$metric]);

        return [
            ['metric' => $metric, 'granularity' => $granularity->value, 'period' => $range->label(), 'filters' => $this->describeFilters($filters), 'currency' => $this->currency(),
                'points' => array_map(fn ($r) => ['period' => $r['period'], 'value' => $this->round($r['value'])], $rows)],
            $this->evidence('trend', sprintf('%s by %s', $this->metrics->get($metric)->label, $granularity->value), $range->label(), $filters, [['key' => 'period', 'label' => ucfirst($granularity->value)], ['key' => 'value', 'label' => $this->metrics->get($metric)->label, 'format' => $format]], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function listAnomalies(array $in): array
    {
        $metric = $in['metric'] ?? null;
        if ($metric !== null && ! isset(AnomalyService::METRICS[$metric])) {
            throw new InvalidArgumentException('metric must be one of: '.implode(', ', array_keys(AnomalyService::METRICS)).'.');
        }
        $severity = $in['severity'] ?? null;
        if ($severity !== null && ! in_array($severity, ['warning', 'critical'], true)) {
            throw new InvalidArgumentException('severity must be warning or critical.');
        }
        $from = isset($in['from']) ? $this->date((string) $in['from'], 'from') : null;
        $to = isset($in['to']) ? $this->date((string) $in['to'], 'to') : null;

        $anomalies = Anomaly::query()
            ->when($metric, fn ($q, $m) => $q->where('metric_key', $m))
            ->when($severity, fn ($q, $s) => $q->where('severity', $s))
            ->when($from, fn ($q, $d) => $q->whereDate('business_date', '>=', $d->toDateString()))
            ->when($to, fn ($q, $d) => $q->whereDate('business_date', '<=', $d->toDateString()))
            ->orderByDesc('business_date')->limit(25)->get();

        $rows = $anomalies->map(fn (Anomaly $a) => [
            'date' => ($a->details['cadence'] ?? 'day') === 'month' ? $a->business_date->format('M Y') : $a->business_date->format('D j M Y'),
            'metric' => AnomalyService::METRICS[$a->metric_key]['label'] ?? $a->metric_key,
            'severity' => ucfirst($a->severity),
            'actual' => (float) $a->actual,
            'expected' => (float) $a->expected,
            'format' => $this->metrics->has($a->metric_key) ? $this->metrics->get($a->metric_key)->format : 'number',
            'explanation' => $a->explanation,
        ])->all();

        $scan = Anomaly::query()->max('detected_at');
        $lastScan = $scan === null ? null : CarbonImmutable::parse((string) $scan, 'UTC')->setTimezone($this->tenant->organization()->timezone)->format('Y-m-d H:i T');

        return [
            ['currency' => $this->currency(), 'last_scan' => $lastScan, 'count' => count($rows),
                'note' => $lastScan === null ? 'No anomaly scan has run yet for this organization.' : 'Scans cover the last 90 days (daily) and last 3 months (expenses).',
                'anomalies' => array_map(fn ($r) => [...$r, 'actual' => $this->round($r['actual']), 'expected' => $this->round($r['expected'])], $rows)],
            $this->evidence('list_anomalies', 'Anomalies', $lastScan ? 'Last 90 days' : 'Not scanned yet', new Filters, [
                ['key' => 'date', 'label' => 'When'], ['key' => 'metric', 'label' => 'Metric'], ['key' => 'severity', 'label' => 'Severity'], ['key' => 'actual', 'label' => 'Actual', 'format' => 'row'], ['key' => 'expected', 'label' => 'Expected', 'format' => 'row'],
            ], $rows),
        ];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function forecast(array $in): array
    {
        $metric = (string) ($in['metric'] ?? '');
        if (! in_array($metric, ForecastService::METRICS, true)) {
            throw new InvalidArgumentException('metric must be one of: '.implode(', ', ForecastService::METRICS).'.');
        }
        $horizon = $this->int($in['horizon'] ?? 3, 1, 12, 'horizon');
        $method = (string) ($in['method'] ?? 'auto');
        $f = $this->forecasts->forecast($metric, $horizon, $method);

        if ($f['message'] !== null) {
            return [['estimate' => true, 'error' => $f['message']], $this->evidence('forecast', "{$f['label']} forecast", 'Not available', new Filters, [['key' => 'note', 'label' => 'Note']], [['note' => $f['message']]], true)];
        }

        $rows = array_map(fn ($p) => ['month' => $p['label'], 'value' => $p['value'], 'lower' => $p['lower'], 'upper' => $p['upper'], 'format' => $f['format']], $f['forecast']);

        return [
            ['estimate' => true, 'metric' => $metric, 'method' => $f['method_label'], 'history_months' => $f['months'], 'backtest_error_pct' => $f['backtest_mape'], 'currency' => $this->currency(),
                'last_actual' => ['month' => end($f['history'])['label'] ?? null, 'value' => $this->round(end($f['history'])['value'] ?? null)],
                'months' => array_map(fn ($r) => ['month' => $r['month'], 'estimate' => $this->round($r['value']), 'low_95' => $this->round($r['lower']), 'high_95' => $this->round($r['upper'])], $rows)],
            $this->evidence('forecast', "{$f['label']} forecast · {$f['method_label']}", 'Next '.count($rows).' months', new Filters, [
                ['key' => 'month', 'label' => 'Month'], ['key' => 'value', 'label' => 'Estimate', 'format' => $f['format']], ['key' => 'lower', 'label' => 'Low (95%)', 'format' => $f['format']], ['key' => 'upper', 'label' => 'High (95%)', 'format' => $f['format']],
            ], $rows, true),
        ];
    }

    // ---- Validation helpers -------------------------------------------------------

    /** @return list<string> */
    private function metricList(mixed $value): array
    {
        if (! is_array($value) || $value === [] || count($value) > 6) {
            throw new InvalidArgumentException('metrics must be a list of 1–6 metric keys.');
        }

        return array_values(array_unique(array_map(fn ($m) => $this->metric($m), $value)));
    }

    private function metric(mixed $value): string
    {
        if (! is_string($value) || ! $this->metrics->has($value)) {
            throw new InvalidArgumentException(sprintf('Unknown metric "%s". Valid keys: %s.', is_scalar($value) ? $value : '?', implode(', ', array_keys($this->metrics->all()))));
        }

        return $value;
    }

    /** @param array<string, mixed> $in */
    private function range(array $in): DateRange
    {
        $from = $this->date((string) ($in['from'] ?? ''), 'from');
        $to = $this->date((string) ($in['to'] ?? ''), 'to');
        if ($from->gt($to)) {
            throw new InvalidArgumentException('from must be on or before to.');
        }
        if ($from->diffInDays($to) > self::MAX_SPAN_DAYS) {
            throw new InvalidArgumentException('Periods are limited to about 3 years.');
        }

        return DateRange::custom($from, $to);
    }

    private function date(string $value, string $field): CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$field} must be a date in YYYY-MM-DD format.");
        }
        $date = CarbonImmutable::instance($parsed);
        if ($date->year < 2000 || $date->gt(CarbonImmutable::now()->addYears(2))) {
            throw new InvalidArgumentException("{$field} is out of range.");
        }

        return $date;
    }

    /**
     * Branch/category ids are checked against this organization's own rows.
     *
     * @param  array<string, mixed>  $in
     */
    private function filters(array $in): Filters
    {
        $ids = function (string $key, string $table) use ($in): array {
            $raw = $in[$key] ?? [];
            if (! is_array($raw) || count($raw) > 50) {
                throw new InvalidArgumentException("{$key} must be a list of ids.");
            }
            $raw = array_values(array_unique(array_map('intval', $raw)));
            if ($raw === []) {
                return [];
            }
            $valid = DB::table($table)->where('organization_id', $this->tenant->id())->whereIn('id', $raw)->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (count($valid) !== count($raw)) {
                throw new InvalidArgumentException("Unknown {$key}: ".implode(', ', array_diff($raw, $valid)).'. Use ids from the system prompt.');
            }
            sort($valid);

            return $valid;
        };

        return new Filters(branchIds: $ids('branch_ids', 'branches'), categoryIds: $ids('category_ids', 'product_categories'));
    }

    private function int(mixed $value, int $min, int $max, string $field): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException("{$field} must be an integer.");
        }
        $v = (int) $value;
        if ($v < $min || $v > $max) {
            throw new InvalidArgumentException("{$field} must be between {$min} and {$max}.");
        }

        return $v;
    }

    // ---- Output helpers ------------------------------------------------------------

    /** @return list<string> */
    private function describeFilters(Filters $filters): array
    {
        $names = fn (string $table, array $ids) => $ids === [] ? [] : DB::table($table)->where('organization_id', $this->tenant->id())->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();

        return [...$names('branches', $filters->branchIds), ...$names('product_categories', $filters->categoryIds)];
    }

    /** @param array<string, float|null> $totals */
    private function noData(array $totals): bool
    {
        return ($totals['orders'] ?? 0) == 0 && ($totals['operating_expenses'] ?? 0) == 0;
    }

    private function currency(): string
    {
        return $this->tenant->organization()->currency;
    }

    private function round(mixed $v): ?float
    {
        return $v === null ? null : round((float) $v, 2);
    }

    /**
     * Evidence shown under the answer, rendered from tool output.
     *
     * @param  list<array{key: string, label: string, format?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function evidence(string $tool, string $title, string $period, Filters $filters, array $columns, array $rows, bool $estimate = false): array
    {
        return [
            'tool' => $tool,
            'title' => $title,
            'period' => $period,
            'filters' => $this->describeFilters($filters),
            'estimate' => $estimate,
            'currency' => $this->currency(),
            'columns' => $columns,
            'rows' => array_slice($rows, 0, 25),
        ];
    }
}
