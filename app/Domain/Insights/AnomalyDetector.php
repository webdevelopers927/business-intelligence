<?php

namespace App\Domain\Insights;

use Carbon\CarbonImmutable;

/**
 * Rolling z-score anomaly detection on a daily series (docs/ARCHITECTURE.md §12).
 * Pure: no database, no tenant — given values, returns flagged days.
 *
 * Baseline for each period:
 *   - weekday mode (daily series): the same weekday over the previous 8 weeks
 *     — Mondays compared with Mondays, because retail has strong weekly
 *     cycles. At least 4 prior same-weekday values are required; before that
 *     nothing is reported (a mixed-weekday baseline flags every Saturday).
 *   - trailing mode (series without a weekly cycle, e.g. monthly totals): the
 *     previous N periods, at least 6 values.
 * Expected = mean, range = mean ± 2σ, z = (actual − mean) / σ.
 * |z| ≥ 2 → warning, |z| ≥ 3 → critical. Periods with too little history or a
 * flat baseline (σ = 0) are never flagged — no signal, no claim.
 */
final class AnomalyDetector
{
    public const WEEKDAY_WEEKS = 8;

    public const MIN_WEEKDAY_BASELINE = 4;

    public const MIN_BASELINE = 6;

    /** @param bool $weekday daily series: compare each day with the same weekday in previous weeks */
    public function __construct(
        private readonly int $window = 28,
        private readonly float $warning = 2.0,
        private readonly float $critical = 3.0,
        private readonly bool $weekday = true,
    ) {}

    /**
     * @param  array<string, float|null>  $series  period key => value, ascending, one entry per period (null = no data)
     * @param  string|null  $from  only report days on/after this date (earlier days are baseline only)
     * @return list<array{date: string, actual: float, expected: float, lower: float, upper: float, z: float, severity: 'warning'|'critical', baseline: 'weekday'|'trailing', sample: int}>
     */
    public function detect(array $series, ?string $from = null): array
    {
        $dates = array_keys($series);
        $values = array_values($series);
        $found = [];

        foreach ($dates as $i => $date) {
            $actual = $values[$i];
            if ($actual === null || ($from !== null && $date < $from)) {
                continue;
            }

            [$baseline, $kind] = $this->baseline($values, $i);
            if (count($baseline) < ($kind === 'weekday' ? self::MIN_WEEKDAY_BASELINE : self::MIN_BASELINE)) {
                continue;
            }

            $mean = array_sum($baseline) / count($baseline);
            $sigma = self::stddev($baseline, $mean);
            // A flat baseline, or variation that is negligible next to the level, carries no signal.
            if ($sigma <= max(1e-9, abs($mean) * 1e-6)) {
                continue;
            }

            $z = ($actual - $mean) / $sigma;
            if (abs($z) < $this->warning) {
                continue;
            }

            $found[] = [
                'date' => (string) $date,
                'actual' => (float) $actual,
                'expected' => $mean,
                'lower' => $mean - 2 * $sigma,
                'upper' => $mean + 2 * $sigma,
                'z' => round($z, 3),
                'severity' => abs($z) >= $this->critical ? 'critical' : 'warning',
                'baseline' => $kind,
                'sample' => count($baseline),
            ];
        }

        return $found;
    }

    /**
     * Weekday mode: same-weekday values from up to 8 previous weeks. Trailing
     * mode: the previous N periods. Nulls (no data) are skipped.
     *
     * @param  list<float|null>  $values
     * @return array{0: list<float>, 1: 'weekday'|'trailing'}
     */
    private function baseline(array $values, int $i): array
    {
        if ($this->weekday) {
            $same = [];
            for ($w = 1; $w <= self::WEEKDAY_WEEKS && $i - 7 * $w >= 0; $w++) {
                $v = $values[$i - 7 * $w];
                if ($v !== null) {
                    $same[] = (float) $v;
                }
            }

            return [$same, 'weekday'];
        }

        $trailing = [];
        for ($k = max(0, $i - $this->window); $k < $i; $k++) {
            if ($values[$k] !== null) {
                $trailing[] = (float) $values[$k];
            }
        }

        return [$trailing, 'trailing'];
    }

    /**
     * Sample standard deviation.
     *
     * @param  list<float>  $values
     */
    public static function stddev(array $values, ?float $mean = null): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean ??= array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    /**
     * Fill a sparse date => value map into one entry per day.
     *
     * @param  array<string, float|null>  $values
     * @return array<string, float|null>
     */
    public static function daily(array $values, CarbonImmutable $from, CarbonImmutable $to, ?float $missing = null): array
    {
        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $out[$key] = array_key_exists($key, $values) && $values[$key] !== null ? (float) $values[$key] : $missing;
        }

        return $out;
    }
}
