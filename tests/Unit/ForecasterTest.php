<?php

use App\Domain\Insights\Forecaster;

it('projects a straight line exactly with the linear trend', function () {
    $history = array_map(fn ($t) => 10.0 + 5 * $t, range(0, 11)); // 10, 15, … 65
    $f = (new Forecaster)->forecast($history, 3, 'linear_trend');

    expect($f['method'])->toBe('linear_trend')
        ->and($f['points'])->each->toEqualWithDelta(0, 1000)
        ->and($f['points'][0])->toEqualWithDelta(70, 1e-9)
        ->and($f['points'][2])->toEqualWithDelta(80, 1e-9)
        ->and($f['backtest_mape'])->toEqualWithDelta(0, 1e-9);
});

it('averages the last k months for moving averages', function () {
    $history = [10.0, 20.0, 30.0, 40.0, 50.0, 60.0];

    expect((new Forecaster)->project('moving_average_3', $history, 2))->toBe([50.0, 50.0])
        ->and((new Forecaster)->project('moving_average_6', $history, 1))->toBe([35.0]);
});

it('repeats last year scaled by year-over-year growth for seasonal naive', function () {
    $year1 = [10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 50];   // December peak
    $year2 = array_map(fn ($v) => $v * 1.2, $year1);              // +20%
    $f = (new Forecaster)->project('seasonal_naive', array_map('floatval', [...$year1, ...$year2]), 13);

    expect($f[0])->toEqualWithDelta(14.4, 1e-9)    // Jan: 12 × 1.2
        ->and($f[11])->toEqualWithDelta(72, 1e-9)  // Dec: 60 × 1.2
        ->and($f[12])->toEqualWithDelta(17.28, 1e-9); // next Jan: grown twice
});

it('only offers seasonal naive with 24 months of history', function () {
    expect(Forecaster::available('seasonal_naive', array_fill(0, 23, 1.0)))->toBeFalse()
        ->and(Forecaster::available('seasonal_naive', array_fill(0, 24, 1.0)))->toBeTrue();

    expect(fn () => (new Forecaster)->forecast(array_fill(0, 18, 1.0), 3, 'seasonal_naive'))
        ->toThrow(InvalidArgumentException::class, 'needs more history');
});

it('picks the most accurate method automatically and widens the band with distance', function () {
    $history = array_map(fn ($t) => 100.0 + 10 * $t + ($t % 2 ? 2 : -2), range(0, 17));
    $f = (new Forecaster)->forecast($history, 6);

    expect($f['method'])->toBe('linear_trend')
        ->and($f['upper'][5] - $f['lower'][5])->toBeGreaterThan($f['upper'][0] - $f['lower'][0])
        ->and($f['lower'][0])->toBeLessThan($f['points'][0])
        ->and($f['upper'][0])->toBeGreaterThan($f['points'][0]);
});

it('refuses to forecast from too little history', function () {
    expect(fn () => (new Forecaster)->forecast([1.0, 2.0], 3))->toThrow(InvalidArgumentException::class, 'At least 3');
});
