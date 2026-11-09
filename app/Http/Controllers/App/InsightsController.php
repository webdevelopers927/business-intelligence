<?php

namespace App\Http\Controllers\App;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Audit\AuditLogger;
use App\Domain\Insights\AnomalyService;
use App\Domain\Insights\Coverage;
use App\Domain\Insights\Forecaster;
use App\Domain\Insights\ForecastService;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Anomaly;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anomalies (stored by the nightly scan) and on-demand forecasts. Every
 * forecast figure is presented as an estimate with its method.
 */
final class InsightsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MetricRegistry $metrics,
    ) {}

    public function index(Request $request, AnalyticsEngine $engine, ForecastService $forecasts): Response
    {
        $data = $request->validate([
            'metric' => ['nullable', Rule::in(array_keys(AnomalyService::METRICS))],
            'severity' => ['nullable', Rule::in(['warning', 'critical'])],
            'forecast' => ['nullable', Rule::in(ForecastService::METRICS)],
            'method' => ['nullable', Rule::in(['auto', ...array_keys(Forecaster::METHODS)])],
            'horizon' => ['nullable', 'integer', Rule::in([3, 6, 12])],
        ]);
        $metric = $data['metric'] ?? 'revenue';
        $org = $this->tenant->organization();

        $anomalies = Anomaly::query()
            ->when($data['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
            ->orderByDesc('business_date')->orderBy('metric_key')
            ->limit(200)
            ->get();

        return Inertia::render('insights/Index', [
            'filters' => ['metric' => $metric, 'severity' => $data['severity'] ?? null, 'forecast' => $data['forecast'] ?? 'revenue', 'method' => $data['method'] ?? 'auto', 'horizon' => (int) ($data['horizon'] ?? 6)],
            'metrics' => collect(AnomalyService::METRICS)->map(fn ($m, $key) => ['value' => $key, 'label' => $m['label'], 'cadence' => $m['cadence'], 'format' => $this->metrics->get($key)->format])->values(),
            'anomalies' => $anomalies->map(fn (Anomaly $a) => [
                'id' => $a->id,
                'metric' => $a->metric_key,
                'metric_label' => AnomalyService::METRICS[$a->metric_key]['label'] ?? $a->metric_key,
                'format' => $this->metrics->has($a->metric_key) ? $this->metrics->get($a->metric_key)->format : 'number',
                // Direction is coloured by business polarity: expenses above expected is bad.
                'polarity' => $this->metrics->has($a->metric_key) ? $this->metrics->get($a->metric_key)->polarity : 'neutral',
                'date' => $a->business_date->toDateString(),
                'cadence' => $a->details['cadence'] ?? 'day',
                'actual' => (float) $a->actual,
                'expected' => (float) $a->expected,
                'lower' => max(0.0, (float) $a->lower_bound),
                'upper' => (float) $a->upper_bound,
                'z' => (float) $a->z_score,
                'severity' => $a->severity,
                'explanation' => $a->explanation,
            ])->values(),
            'counts' => [
                'critical' => Anomaly::query()->where('severity', 'critical')->count(),
                'warning' => Anomaly::query()->where('severity', 'warning')->count(),
            ],
            // Stored in UTC without an offset; send ISO-8601 so browsers don't read it as local time.
            'lastScan' => ($scan = Anomaly::query()->max('detected_at')) ? CarbonImmutable::parse((string) $scan, 'UTC')->toIso8601String() : null,
            'series' => Inertia::defer(fn () => $this->series($engine, $metric), 'charts'),
            'forecast' => Inertia::defer(fn () => $forecasts->forecast($data['forecast'] ?? 'revenue', (int) ($data['horizon'] ?? 6), $data['method'] ?? 'auto'), 'charts'),
            'forecastMetrics' => collect(ForecastService::METRICS)->map(fn ($k) => ['value' => $k, 'label' => $this->metrics->get($k)->label])->values(),
            'canScan' => $request->user()?->can(Permission::MetricsManage->value) ?? false,
            'timezone' => $org->timezone,
        ]);
    }

    public function scan(AnomalyService $service, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize(Permission::MetricsManage->value);

        $result = $service->scan();
        $audit->record('insights.scanned', null, $result);
        $this->toast(sprintf('Scan complete: %d anomal%s in the last 90 days.', $result['detected'], $result['detected'] === 1 ? 'y' : 'ies'));

        return back();
    }

    /**
     * The monitored metric over the scan window — daily for 90 days, or
     * monthly for 15 months — for the chart behind the anomaly markers.
     *
     * @return array{cadence: string, keys: list<string>, labels: list<string>, values: list<float|null>}
     */
    private function series(AnalyticsEngine $engine, string $metric): array
    {
        $today = CarbonImmutable::now($this->tenant->organization()->timezone)->startOfDay();
        $monthly = AnomalyService::METRICS[$metric]['cadence'] === 'month';
        $range = $monthly
            ? DateRange::custom($today->startOfMonth()->subMonths(15), $today->startOfMonth()->subDay())
            : DateRange::custom($today->subDays(AnomalyService::SCAN_DAYS), $today->subDay());
        $granularity = $monthly ? Granularity::Month : Granularity::Day;
        $s = $engine->series($range, new Filters, [$metric], $granularity);

        $start = Coverage::start($this->tenant->id(), $this->metrics->get($metric));
        $values = [];
        foreach ($s['keys'] as $i => $key) {
            $inCoverage = $start !== null && $key >= ($monthly ? substr($start, 0, 7) : $start);
            $values[] = $inCoverage ? (float) ($s['series'][$metric][$i] ?? 0) : null;
        }

        return ['cadence' => $monthly ? 'month' : 'day', 'keys' => $s['keys'], 'labels' => $s['labels'], 'values' => $values];
    }
}
