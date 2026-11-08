<?php

namespace App\Domain\Analytics\Query;

use Carbon\CarbonImmutable;

enum Granularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** Sensible default bucket size for a range length. */
    public static function forDays(int $days): self
    {
        return match (true) {
            $days <= 45 => self::Day,
            $days <= 190 => self::Week,
            default => self::Month,
        };
    }

    /** Bucket key a date falls into (matches TimeBucket SQL output). */
    public function keyFor(CarbonImmutable $date): string
    {
        return match ($this) {
            self::Day => $date->toDateString(),
            self::Week => $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            self::Month => $date->format('Y-m'),
        };
    }

    /**
     * Every bucket key between two dates, in order — used to fill gaps so a
     * day without sales is 0, not missing.
     *
     * @return list<string>
     */
    public function keysBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $keys = [];
        $cursor = match ($this) {
            self::Day => $from,
            self::Week => $from->startOfWeek(CarbonImmutable::MONDAY),
            self::Month => $from->startOfMonth(),
        };

        while ($cursor->lte($to)) {
            $keys[] = $this->keyFor($cursor);
            $cursor = match ($this) {
                self::Day => $cursor->addDay(),
                self::Week => $cursor->addWeek(),
                self::Month => $cursor->addMonthNoOverflow(),
            };
        }

        return $keys;
    }

    public function label(string $key): string
    {
        return match ($this) {
            self::Day => CarbonImmutable::parse($key)->format('M j'),
            self::Week => 'Week of '.CarbonImmutable::parse($key)->format('M j'),
            self::Month => CarbonImmutable::parse($key.'-01')->format('M Y'),
        };
    }
}
