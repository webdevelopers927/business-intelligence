<?php

namespace App\Domain\Analytics\Query;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An inclusive range of business dates (organization timezone) plus the
 * rule for its comparison period (docs/ARCHITECTURE.md §7.1).
 *
 * Comparison rules:
 *  - rolling presets (last 7/30 days, custom, today, yesterday) compare with
 *    the immediately preceding window of equal length;
 *  - calendar-to-date presets (this month/quarter/year) compare with the
 *    same elapsed portion of the previous period — Nov 1–9 vs Oct 1–9 — with
 *    day numbers clamped to the shorter month (Mar 31 → Feb 28/29);
 *  - complete calendar periods (last month) compare with the full period before.
 */
final readonly class DateRange
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'custom' => 'Custom range',
    ];

    public const DEFAULT_PRESET = 'this_month';

    public const MAX_DAYS = 1100;

    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $preset,
    ) {}

    public static function fromPreset(string $preset, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();

        return match ($preset) {
            'today' => new self($today, $today, $preset),
            'yesterday' => new self($today->subDay(), $today->subDay(), $preset),
            'last_7_days' => new self($today->subDays(6), $today, $preset),
            'last_30_days' => new self($today->subDays(29), $today, $preset),
            'this_month' => new self($today->startOfMonth(), $today, $preset),
            'last_month' => new self($today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay(), $preset),
            'this_quarter' => new self($today->startOfQuarter(), $today, $preset),
            'this_year' => new self($today->startOfYear(), $today, $preset),
            default => throw new InvalidArgumentException("Unknown date preset [{$preset}]."),
        };
    }

    public static function custom(CarbonImmutable $from, CarbonImmutable $to): self
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw new InvalidArgumentException('Date ranges are limited to '.self::MAX_DAYS.' days.');
        }

        return new self($from, $to, 'custom');
    }

    /**
     * A calendar month (used by drill-down). A month still in progress
     * behaves like "this month" (to date, vs the same days of the previous
     * month); a complete month compares with the whole previous month.
     */
    public static function month(CarbonImmutable $month, CarbonImmutable $today): self
    {
        $start = $month->startOfMonth()->startOfDay();
        $today = $today->startOfDay();

        if ($start->format('Y-m') === $today->format('Y-m')) {
            return self::fromPreset('this_month', $today);
        }

        return new self($start, $start->endOfMonth()->startOfDay(), 'month');
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function previous(): self
    {
        return match ($this->preset) {
            'this_month' => $this->sameElapsed($this->from->subMonthNoOverflow()->startOfMonth(), fn (CarbonImmutable $d) => $d->endOfMonth()),
            'this_quarter' => $this->sameElapsed($this->from->subMonthsNoOverflow(3)->startOfQuarter(), fn (CarbonImmutable $d) => $d->endOfQuarter()),
            'this_year' => $this->sameElapsed($this->from->subYearNoOverflow()->startOfYear(), fn (CarbonImmutable $d) => $d->endOfYear()),
            'last_month', 'month' => new self(
                $this->from->subMonthNoOverflow()->startOfMonth(),
                $this->from->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                'custom',
            ),
            default => new self($this->from->subDays($this->days()), $this->from->subDay(), 'custom'),
        };
    }

    /**
     * The previous period's start plus the same number of elapsed days,
     * clamped to that period's end (so Mar 1–31 compares with all of Feb).
     *
     * @param  callable(CarbonImmutable): CarbonImmutable  $periodEnd
     */
    private function sameElapsed(CarbonImmutable $start, callable $periodEnd): self
    {
        $end = $start->addDays($this->days() - 1);
        $limit = $periodEnd($start)->startOfDay();

        return new self($start, $end->gt($limit) ? $limit : $end, 'custom');
    }

    public function granularity(): Granularity
    {
        return Granularity::forDays($this->days());
    }

    public function label(): string
    {
        return self::formatSpan($this->from, $this->to);
    }

    public function presetLabel(): string
    {
        return isset(self::PRESETS[$this->preset]) && $this->preset !== 'custom' ? self::PRESETS[$this->preset] : $this->label();
    }

    /** Human span: "Nov 9, 2026", "Nov 1–9, 2026", "Oct 28 – Nov 3, 2026", "Dec 1, 2025 – Jan 4, 2026". */
    public static function formatSpan(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return match (true) {
            $from->equalTo($to) => $from->format('M j, Y'),
            $from->year !== $to->year => $from->format('M j, Y').' – '.$to->format('M j, Y'),
            $from->month === $to->month => $from->format('M j').'–'.$to->format('j, Y'),
            default => $from->format('M j').' – '.$to->format('M j, Y'),
        };
    }

    /** @return array{from: string, to: string, preset: string, label: string, days: int} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'preset' => $this->preset,
            'label' => $this->label(),
            'days' => $this->days(),
        ];
    }
}
