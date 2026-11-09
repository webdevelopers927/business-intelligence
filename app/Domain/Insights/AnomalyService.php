<?php

namespace App\Domain\Insights;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Money;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Analytics\Query\Granularity;
use App\Domain\Tenancy\TenantContext;
use App\Models\Anomaly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Runs AnomalyDetector over the current organization's series and keeps the
 * `anomalies` table in sync for the scan window. Idempotent: periods that no
 * longer qualify (e.g. after a corrected re-import) are removed.
 *
 * Cadence per metric:
 *   revenue, orders      — daily, last 90 complete days, weekday-aware baseline
 *   operating_expenses   — monthly, last 3 complete months vs the previous 12.
 *     Expenses are booked in lumps (rent, payroll at month end), so a daily
 *     check would flag every pay day; a monthly check flags months that are
 *     genuinely out of line.
 */
final class AnomalyService
{
    /** @var array<string, array{label: string, cadence: 'day'|'month'}> */
    public const METRICS = [
        'revenue' => ['label' => 'Revenue', 'cadence' => 'day'],
        'orders' => ['label' => 'Orders', 'cadence' => 'day'],
        'operating_expenses' => ['label' => 'Operating expenses', 'cadence' => 'month'],
    ];

    public const SCAN_DAYS = 90;

    public const SCAN_MONTHS = 3;

    public const MONTH_WINDOW = 12;

    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly MetricRegistry $metrics,
        private readonly TenantContext $tenant,
    ) {}

    /** @return array{detected: int, removed: int} */
    public function scan(?CarbonImmutable $now = null): array
    {
        $org = $this->tenant->organization();
        $today = ($now ?? CarbonImmutable::now())->setTimezone($org->timezone)->startOfDay();

        // Daily metrics: last 90 complete days, with 8 weeks of baseline before.
        $dayTo = $today->subDay();
        $dayFrom = $dayTo->subDays(self::SCAN_DAYS - 1);
        $daily = $this->run(
            array_keys(array_filter(self::METRICS, fn ($m) => $m['cadence'] === 'day')),
            DateRange::custom($dayFrom->subDays(7 * AnomalyDetector::WEEKDAY_WEEKS), $dayTo),
            Granularity::Day,
            $dayFrom->toDateString(),
            new AnomalyDetector((int) config('orbit.insights.window', 28), ...$this->thresholds()),
        );

        // Monthly metrics: last 3 complete months, each against the previous 12.
        $monthTo = $today->startOfMonth()->subDay();
        $monthFrom = $monthTo->startOfMonth()->subMonths(self::SCAN_MONTHS - 1);
        $monthly = $this->run(
            array_keys(array_filter(self::METRICS, fn ($m) => $m['cadence'] === 'month')),
            DateRange::custom($monthFrom->subMonths(self::MONTH_WINDOW), $monthTo),
            Granularity::Month,
            $monthFrom->format('Y-m'),
            new AnomalyDetector(self::MONTH_WINDOW, ...[...$this->thresholds(), false]),
        );

        return DB::transaction(function () use ($daily, $monthly, $dayFrom, $dayTo, $monthFrom, $org) {
            $kept = [];
            foreach ([...$daily, ...$monthly] as [$metric, $hit, $contributor, $date]) {
                // whereDate: SQLite stores date casts as "Y-m-d 00:00:00", MySQL as DATE.
                $anomaly = Anomaly::query()->where('metric_key', $metric)->whereDate('business_date', $date)->first()
                    ?? new Anomaly(['metric_key' => $metric, 'business_date' => $date]);
                $anomaly->fill([
                    'actual' => $hit['actual'],
                    'expected' => $hit['expected'],
                    'lower_bound' => $hit['lower'],
                    'upper_bound' => $hit['upper'],
                    'z_score' => $hit['z'],
                    'severity' => $hit['severity'],
                    'baseline' => self::METRICS[$metric]['cadence'] === 'month' ? 'monthly' : $hit['baseline'],
                    'explanation' => $this->explain($metric, $hit, $contributor, $org->currency),
                    'details' => ['sample' => $hit['sample'], 'contributor' => $contributor, 'cadence' => self::METRICS[$metric]['cadence']],
                    'detected_at' => now(),
                ])->save();
                $kept[] = $anomaly->id;
            }

            $dailyKeys = array_keys(array_filter(self::METRICS, fn ($m) => $m['cadence'] === 'day'));
            $removed = Anomaly::query()
                ->where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereIn('metric_key', $dailyKeys)->whereDate('business_date', '>=', $dayFrom->toDateString())->whereDate('business_date', '<=', $dayTo->toDateString()))
                    ->orWhere(fn ($q) => $q->whereNotIn('metric_key', $dailyKeys)->whereDate('business_date', '>=', $monthFrom->toDateString())->whereDate('business_date', '<=', $dayTo->toDateString())))
                ->whereNotIn('id', $kept === [] ? [0] : $kept)
                ->delete();

            return ['detected' => count($kept), 'removed' => (int) $removed];
        });
    }

    /** @return array{0: float, 1: float} */
    private function thresholds(): array
    {
        return [(float) config('orbit.insights.warning_z', 2.0), (float) config('orbit.insights.critical_z', 3.0)];
    }

    /**
     * @param  list<string>  $metrics
     * @return list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>|null, 3: string}>
     */
    private function run(array $metrics, DateRange $range, Granularity $granularity, string $reportFrom, AnomalyDetector $detector): array
    {
        if ($metrics === []) {
            return [];
        }

        $all = $this->engine->series($range, new Filters, $metrics, $granularity);
        $branches = $this->branchSeries($range, $metrics, $granularity);
        $found = [];

        foreach ($metrics as $metric) {
            // Before the metric's data begins there is no data, not zero.
            $start = Coverage::start($this->tenant->id(), $this->metrics->get($metric));
            if ($start !== null && $granularity === Granularity::Month) {
                $start = substr($start, 0, 7);
            }
            $series = [];
            foreach ($all['keys'] as $i => $key) {
                $series[$key] = $start !== null && $key >= $start ? (float) ($all['series'][$metric][$i] ?? 0) : null;
            }

            foreach ($detector->detect($series, $reportFrom) as $hit) {
                $date = $granularity === Granularity::Month ? $hit['date'].'-01' : $hit['date'];
                $window = $granularity === Granularity::Month ? self::MONTH_WINDOW : (int) config('orbit.insights.window', 28);
                $found[] = [$metric, $hit, $this->contributor($metric, $hit, $branches, $window), $date];
            }
        }

        return $found;
    }

    /**
     * Series per branch, used to say which branch drove an anomaly.
     *
     * @param  list<string>  $metrics
     * @return list<array{name: string, series: array<string, list<float|null>>, keys: list<string>}>
     */
    private function branchSeries(DateRange $range, array $metrics, Granularity $granularity): array
    {
        $branches = DB::table('branches')->where('organization_id', $this->tenant->id())->orderBy('name')->get(['id', 'name']);
        $out = [];
        foreach ($branches as $b) {
            $s = $this->engine->series($range, new Filters(branchIds: [(int) $b->id]), $metrics, $granularity);
            $out[] = ['name' => (string) $b->name, 'series' => $s['series'], 'keys' => $s['keys']];
        }

        return $out;
    }

    /**
     * The branch whose value deviates most from its own baseline (same baseline
     * periods as the organization-level check), in the anomaly's direction.
     *
     * @param  array<string, mixed>  $hit
     * @param  list<array{name: string, series: array<string, list<float|null>>, keys: list<string>}>  $branches
     * @return array{branch: string, actual: float, expected: float, delta: float, share: float|null}|null
     */
    private function contributor(string $metric, array $hit, array $branches, int $window): ?array
    {
        $best = null;
        $orgDelta = $hit['actual'] - $hit['expected'];
        foreach ($branches as $b) {
            $i = array_search($hit['date'], $b['keys'], true);
            if ($i === false) {
                continue;
            }
            $values = $b['series'][$metric];
            $baseline = [];
            if ($hit['baseline'] === 'weekday') {
                for ($w = 1; $w <= AnomalyDetector::WEEKDAY_WEEKS; $w++) {
                    $baseline[] = (float) ($values[$i - 7 * $w] ?? 0);
                }
            } else {
                for ($k = max(0, $i - $window); $k < $i; $k++) {
                    $baseline[] = (float) ($values[$k] ?? 0);
                }
            }
            if ($baseline === []) {
                continue;
            }
            $expected = array_sum($baseline) / count($baseline);
            $actual = (float) ($values[$i] ?? 0);
            $delta = $actual - $expected;
            if ($delta == 0.0 || ($orgDelta < 0) !== ($delta < 0)) {
                continue;
            }
            if ($best === null || abs($delta) > abs($best['delta'])) {
                $best = ['branch' => $b['name'], 'actual' => $actual, 'expected' => $expected, 'delta' => $delta, 'share' => $orgDelta != 0.0 ? round($delta / $orgDelta * 100, 1) : null];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $hit
     * @param  array{branch: string, actual: float, expected: float, delta: float, share: float|null}|null  $contributor
     */
    private function explain(string $metric, array $hit, ?array $contributor, string $currency): string
    {
        $monthly = self::METRICS[$metric]['cadence'] === 'month';
        $date = CarbonImmutable::parse($monthly ? $hit['date'].'-01' : $hit['date']);
        $format = $this->metrics->get($metric)->format;
        $fmt = fn (float $v) => $format === 'currency' ? Money::compact($v, $currency) : number_format($v);
        $pct = $hit['expected'] != 0.0 ? ($hit['actual'] - $hit['expected']) / abs($hit['expected']) * 100 : null;
        $direction = $hit['actual'] < $hit['expected'] ? 'below' : 'above';
        $basis = match (true) {
            $monthly => sprintf('the average of the previous %d months', $hit['sample']),
            $hit['baseline'] === 'weekday' => sprintf('a typical %s (average of the previous %d %ss)', $date->format('l'), $hit['sample'], $date->format('l')),
            default => sprintf('the average of the previous %d days', $hit['sample']),
        };

        $text = sprintf(
            '%s %s %s %s, %s %s the expected %s, based on %s. Normal range: %s – %s.',
            self::METRICS[$metric]['label'],
            $monthly ? 'in '.$date->format('F Y') : 'on '.$date->format('D j M Y'),
            $metric === 'orders' || $monthly ? 'were' : 'was',
            $fmt($hit['actual']),
            Money::magnitude($pct),
            $direction,
            $fmt($hit['expected']),
            $basis,
            $fmt(max(0, $hit['lower'])),
            $fmt($hit['upper']),
        );

        if ($contributor !== null && $contributor['share'] !== null && $contributor['share'] >= 25) {
            $text .= sprintf(
                ' %s accounts for about %s of the gap (%s vs its usual %s).',
                $contributor['branch'],
                number_format(min(100, $contributor['share'])).'%',
                $fmt($contributor['actual']),
                $fmt($contributor['expected']),
            );
        }

        return $text;
    }
}
