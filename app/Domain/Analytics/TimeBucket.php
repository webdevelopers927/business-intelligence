<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Query\Granularity;
use Illuminate\Support\Facades\DB;

/**
 * The only place with dialect-specific SQL for time bucketing (D-01a).
 * Output keys match Granularity::keyFor(): Y-m-d (day, week = Monday) or Y-m.
 */
final class TimeBucket
{
    public static function expression(Granularity $granularity, string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ([$driver, $granularity]) {
            ['sqlite', Granularity::Day], ['mysql', Granularity::Day], ['mariadb', Granularity::Day] => "date({$column})",
            ['sqlite', Granularity::Week] => "date({$column}, '-' || ((cast(strftime('%w', {$column}) as integer) + 6) % 7) || ' days')",
            ['sqlite', Granularity::Month] => "strftime('%Y-%m', {$column})",
            ['mysql', Granularity::Week], ['mariadb', Granularity::Week] => "DATE_FORMAT(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY), '%Y-%m-%d')",
            ['mysql', Granularity::Month], ['mariadb', Granularity::Month] => "DATE_FORMAT({$column}, '%Y-%m')",
            ['pgsql', Granularity::Day] => "to_char({$column}, 'YYYY-MM-DD')",
            ['pgsql', Granularity::Week] => "to_char(date_trunc('week', {$column}), 'YYYY-MM-DD')",
            ['pgsql', Granularity::Month] => "to_char({$column}, 'YYYY-MM')",
            default => throw new \LogicException("No time bucket expression for driver [{$driver}]."),
        };
    }
}
