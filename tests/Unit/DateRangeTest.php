<?php

use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Granularity;
use Carbon\CarbonImmutable;

function span(DateRange $r): string
{
    return $r->from->toDateString().'..'.$r->to->toDateString();
}

it('resolves presets and their comparison periods', function (string $preset, string $today, string $range, string $previous) {
    $r = DateRange::fromPreset($preset, CarbonImmutable::parse($today));

    expect(span($r))->toBe($range)
        ->and(span($r->previous()))->toBe($previous);
})->with([
    'today' => ['today', '2026-11-09', '2026-11-09..2026-11-09', '2026-11-08..2026-11-08'],
    'yesterday' => ['yesterday', '2026-11-09', '2026-11-08..2026-11-08', '2026-11-07..2026-11-07'],
    'last 7 days' => ['last_7_days', '2026-11-09', '2026-11-03..2026-11-09', '2026-10-27..2026-11-02'],
    'last 30 days' => ['last_30_days', '2026-11-09', '2026-10-11..2026-11-09', '2026-09-11..2026-10-10'],
    'this month, to date' => ['this_month', '2026-11-09', '2026-11-01..2026-11-09', '2026-10-01..2026-10-09'],
    'this month on the 31st clamps into Feb' => ['this_month', '2026-03-31', '2026-03-01..2026-03-31', '2026-02-01..2026-02-28'],
    'leap year February' => ['this_month', '2028-03-31', '2028-03-01..2028-03-31', '2028-02-01..2028-02-29'],
    'last month' => ['last_month', '2026-11-09', '2026-10-01..2026-10-31', '2026-09-01..2026-09-30'],
    'last month across a year' => ['last_month', '2026-01-15', '2025-12-01..2025-12-31', '2025-11-01..2025-11-30'],
    'this quarter, to date' => ['this_quarter', '2026-11-09', '2026-10-01..2026-11-09', '2026-07-01..2026-08-09'],
    'this year, to date' => ['this_year', '2026-11-09', '2026-01-01..2026-11-09', '2025-01-01..2025-11-09'],
]);

it('compares a custom range with the preceding window of equal length', function () {
    $r = DateRange::custom(CarbonImmutable::parse('2026-10-31'), CarbonImmutable::parse('2026-10-01'));

    expect(span($r))->toBe('2026-10-01..2026-10-31')
        ->and($r->days())->toBe(31)
        ->and(span($r->previous()))->toBe('2026-08-31..2026-09-30');
});

it('compares a drilled-into calendar month with the whole previous month', function () {
    $today = CarbonImmutable::parse('2026-11-09');

    $september = DateRange::month(CarbonImmutable::parse('2026-09-01'), $today);
    $march = DateRange::month(CarbonImmutable::parse('2026-03-01'), $today);
    $current = DateRange::month(CarbonImmutable::parse('2026-11-01'), $today);

    expect(span($september))->toBe('2026-09-01..2026-09-30')
        ->and(span($september->previous()))->toBe('2026-08-01..2026-08-31')
        ->and(span($march->previous()))->toBe('2026-02-01..2026-02-28')
        ->and(span($current))->toBe('2026-11-01..2026-11-09')
        ->and(span($current->previous()))->toBe('2026-10-01..2026-10-09');
});

it('rejects absurdly long custom ranges', function () {
    DateRange::custom(CarbonImmutable::parse('2020-01-01'), CarbonImmutable::parse('2026-01-01'));
})->throws(InvalidArgumentException::class);

it('formats human labels', function (string $from, string $to, string $label) {
    expect(DateRange::formatSpan(CarbonImmutable::parse($from), CarbonImmutable::parse($to)))->toBe($label);
})->with([
    ['2026-11-09', '2026-11-09', 'Nov 9, 2026'],
    ['2026-11-01', '2026-11-09', 'Nov 1–9, 2026'],
    ['2026-10-28', '2026-11-03', 'Oct 28 – Nov 3, 2026'],
    ['2025-12-01', '2026-01-04', 'Dec 1, 2025 – Jan 4, 2026'],
]);

it('picks a granularity and enumerates buckets', function () {
    expect(Granularity::forDays(31))->toBe(Granularity::Day)
        ->and(Granularity::forDays(90))->toBe(Granularity::Week)
        ->and(Granularity::forDays(365))->toBe(Granularity::Month)
        ->and(Granularity::Week->keysBetween(CarbonImmutable::parse('2026-11-04'), CarbonImmutable::parse('2026-11-17')))
        ->toBe(['2026-11-02', '2026-11-09', '2026-11-16'])
        ->and(Granularity::Month->keysBetween(CarbonImmutable::parse('2026-01-31'), CarbonImmutable::parse('2026-03-01')))
        ->toBe(['2026-01', '2026-02', '2026-03']);
});
