<?php

use App\Domain\Insights\AnomalyDetector;

/** A weekly pattern (Mon..Sun) with small alternating noise, for `weeks` weeks. */
function weeklySeries(int $weeks, array $overrides = []): array
{
    $pattern = [100, 90, 95, 110, 150, 200, 180];
    $series = [];
    $date = new DateTimeImmutable('2026-01-05'); // a Monday
    for ($i = 0; $i < $weeks * 7; $i++) {
        $series[$date->format('Y-m-d')] = $pattern[$i % 7] + ($i % 2 === 0 ? 3.0 : -3.0);
        $date = $date->modify('+1 day');
    }

    return array_replace($series, $overrides);
}

it('flags a spike as critical and a moderate dip as a warning, against the same weekday', function () {
    $series = weeklySeries(10, ['2026-03-12' => 400.0, '2026-03-13' => 140.0]); // Thu spike, Fri (usual ~150) dip
    $hits = collect((new AnomalyDetector)->detect($series))->keyBy('date');

    expect($hits->keys()->all())->toContain('2026-03-12')
        ->and($hits['2026-03-12']['severity'])->toBe('critical')
        ->and($hits['2026-03-12']['baseline'])->toBe('weekday')
        ->and($hits['2026-03-12']['expected'])->toEqualWithDelta(110, 3.1)
        ->and($hits['2026-03-12']['z'])->toBeGreaterThan(3);
});

it('does not flag the normal weekly cycle once weekday baselines are available', function () {
    // Saturdays are double Mondays — normal for retail, not an anomaly.
    $hits = (new AnomalyDetector)->detect(weeklySeries(12), '2026-03-02');

    expect($hits)->toBe([]);
});

it('stays silent on daily series until 4 same-weekday values exist', function () {
    // Three weeks of the normal cycle: a mixed-weekday baseline would flag every Saturday.
    expect((new AnomalyDetector)->detect(weeklySeries(3)))->toBe([]);

    $spikeInWeek5 = weeklySeries(5, ['2026-02-05' => 400.0]); // 5th Thursday: 4 prior Thursdays
    expect(array_column((new AnomalyDetector)->detect($spikeInWeek5), 'date'))->toBe(['2026-02-05']);
});

it('uses a trailing window for series without a weekly cycle, and skips thin or flat baselines', function () {
    $months = fn (array $values) => array_combine(array_map(fn ($i) => sprintf('2025-%02d', $i + 1), array_keys($values)), $values);
    $detector = new AnomalyDetector(12, weekday: false);

    expect($detector->detect($months(array_fill(0, 10, 50.0) + [10 => 500.0])))->toBe([]); // σ = 0 → no claim
    expect($detector->detect($months([10.0, 12.0, 11.0, 900.0])))->toBe([]); // fewer than 6 baseline points

    $hit = $detector->detect($months([95.0, 105.0, 95.0, 105.0, 95.0, 105.0, 95.0, 105.0, 160.0]))[0];
    expect($hit['baseline'])->toBe('trailing')->and($hit['date'])->toBe('2025-09')->and($hit['severity'])->toBe('critical');
});

it('treats null as missing data rather than zero', function () {
    $series = weeklySeries(10, ['2026-03-12' => null]);
    $dates = array_column((new AnomalyDetector)->detect($series), 'date');

    expect($dates)->not->toContain('2026-03-12')->and($dates)->toBe([]);
});

it('computes a sample standard deviation', function () {
    expect(AnomalyDetector::stddev([2, 4, 4, 4, 5, 5, 7, 9]))->toEqualWithDelta(2.138, 0.001)
        ->and(AnomalyDetector::stddev([5]))->toBe(0.0);
});
