<?php

namespace App\Domain\Insights;

use InvalidArgumentException;

/**
 * Monthly forecasts (docs/ARCHITECTURE.md §12). Pure maths over a list of
 * complete months; every result carries its method so the UI can label it
 * "Estimate · <method>".
 *
 * Methods:
 *   moving_average_3 / moving_average_6 — flat at the mean of the last k months
 *   linear_trend      — ordinary least squares y = a + b·t
 *   seasonal_naive    — same month last year × year-over-year growth (needs ≥ 24 months)
 *   auto              — the applicable method with the lowest error when
 *                       back-tested on the last 3 known months
 *
 * Band: point ± 1.96 · σ · √h, where σ is the method's one-step-ahead
 * in-sample residual standard deviation and h the step (uncertainty grows
 * with distance). Bounds are not clipped; callers may floor at zero.
 */
final class Forecaster
{
    public const METHODS = [
        'moving_average_3' => '3-month moving average',
        'moving_average_6' => '6-month moving average',
        'linear_trend' => 'Linear trend',
        'seasonal_naive' => 'Seasonal naive with trend',
    ];

    public const MIN_HISTORY = 3;

    public const BACKTEST = 3;

    /** @param list<float> $history */
    public static function available(string $method, array $history): bool
    {
        $n = count($history);

        return match ($method) {
            'moving_average_3' => $n >= 3,
            'moving_average_6' => $n >= 6,
            'linear_trend' => $n >= 6,
            'seasonal_naive' => $n >= 24,
            default => false,
        };
    }

    /**
     * @param  list<float>  $history  oldest → newest complete months
     * @return array{method: string, label: string, points: list<float>, lower: list<float>, upper: list<float>, sigma: float, backtest_mape: float|null}
     */
    public function forecast(array $history, int $horizon, string $method = 'auto'): array
    {
        if (count($history) < self::MIN_HISTORY) {
            throw new InvalidArgumentException('At least '.self::MIN_HISTORY.' complete months of history are needed to forecast.');
        }
        if ($horizon < 1 || $horizon > 24) {
            throw new InvalidArgumentException('Horizon must be between 1 and 24 months.');
        }

        $mape = null;
        if ($method === 'auto') {
            [$method, $mape] = $this->choose($history);
        } elseif (! self::available($method, $history)) {
            throw new InvalidArgumentException(sprintf('%s needs more history (%d months available).', self::METHODS[$method] ?? $method, count($history)));
        } else {
            $mape = $this->backtest($method, $history);
        }

        $points = $this->project($method, $history, $horizon);
        $sigma = $this->residualSigma($method, $history);
        $lower = $upper = [];
        foreach ($points as $h => $p) {
            $half = 1.96 * $sigma * sqrt($h + 1);
            $lower[] = $p - $half;
            $upper[] = $p + $half;
        }

        return ['method' => $method, 'label' => self::METHODS[$method], 'points' => $points, 'lower' => $lower, 'upper' => $upper, 'sigma' => $sigma, 'backtest_mape' => $mape];
    }

    /**
     * Point forecasts for steps 1..h.
     *
     * @param  list<float>  $y
     * @return list<float>
     */
    public function project(string $method, array $y, int $h): array
    {
        $n = count($y);

        switch ($method) {
            case 'moving_average_3':
            case 'moving_average_6':
                $k = $method === 'moving_average_3' ? 3 : 6;
                $mean = array_sum(array_slice($y, -$k)) / $k;

                return array_fill(0, $h, $mean);

            case 'linear_trend':
                [$a, $b] = self::ols($y);

                return array_map(fn (int $s) => $a + $b * ($n - 1 + $s), range(1, $h));

            case 'seasonal_naive':
                $last = array_sum(array_slice($y, -12));
                $prior = array_sum(array_slice($y, -24, 12));
                $growth = $prior > 0 ? $last / $prior : 1.0;

                // Same calendar month in the last known year, grown once per year ahead.
                return array_map(fn (int $s) => $y[$n - 12 + ($s - 1) % 12] * $growth ** (intdiv($s - 1, 12) + 1), range(1, $h));
        }

        throw new InvalidArgumentException("Unknown forecast method [{$method}].");
    }

    /**
     * @param  list<float>  $y
     * @return array{0: float, 1: float} intercept, slope over t = 0..n-1
     */
    public static function ols(array $y): array
    {
        $n = count($y);
        $tMean = ($n - 1) / 2;
        $yMean = array_sum($y) / $n;
        $num = $den = 0.0;
        foreach ($y as $t => $v) {
            $num += ($t - $tMean) * ($v - $yMean);
            $den += ($t - $tMean) ** 2;
        }
        $b = $den > 0 ? $num / $den : 0.0;

        return [$yMean - $b * $tMean, $b];
    }

    /**
     * @param  list<float>  $history
     * @return array{0: string, 1: float|null}
     */
    private function choose(array $history): array
    {
        $best = null;
        $bestError = INF;
        foreach (array_keys(self::METHODS) as $method) {
            if (! self::available($method, $history)) {
                continue;
            }
            $error = $this->backtest($method, $history);
            // Ties (or no back-test possible) keep the simpler, earlier method.
            if ($best === null || ($error !== null && $error < $bestError)) {
                $best = $method;
                $bestError = $error ?? INF;
            }
        }

        return [$best ?? 'moving_average_3', is_finite($bestError) ? $bestError : null];
    }

    /**
     * Mean absolute percentage error forecasting each of the last 3 months
     * from the months before it. Null when there isn't enough history.
     *
     * @param  list<float>  $history
     */
    public function backtest(string $method, array $history): ?float
    {
        $errors = [];
        for ($k = self::BACKTEST; $k >= 1; $k--) {
            $train = array_slice($history, 0, count($history) - $k);
            $actual = $history[count($history) - $k];
            if (! self::available($method, $train) || $actual == 0.0) {
                continue;
            }
            $errors[] = abs($this->project($method, $train, 1)[0] - $actual) / abs($actual) * 100;
        }

        return $errors === [] ? null : round(array_sum($errors) / count($errors), 2);
    }

    /**
     * σ of one-step-ahead in-sample errors (falls back to σ of the history).
     *
     * @param  list<float>  $y
     */
    private function residualSigma(string $method, array $y): float
    {
        $residuals = [];
        for ($t = 1; $t < count($y); $t++) {
            $train = array_slice($y, 0, $t);
            if (self::available($method, $train)) {
                $residuals[] = $y[$t] - $this->project($method, $train, 1)[0];
            }
        }

        return count($residuals) >= 2 ? AnomalyDetector::stddev($residuals, 0.0 + array_sum($residuals) / count($residuals)) : AnomalyDetector::stddev($y);
    }
}
