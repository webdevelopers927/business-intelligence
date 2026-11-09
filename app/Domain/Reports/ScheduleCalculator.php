<?php

namespace App\Domain\Reports;

use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;

/**
 * Next run time for a schedule, computed in the organization's timezone and
 * stored in UTC. Monthly schedules on the 29th–31st clamp to the month's
 * last day (Feb 28/29).
 */
final class ScheduleCalculator
{
    public static function next(ReportSchedule $schedule, string $timezone, ?CarbonImmutable $after = null): CarbonImmutable
    {
        $now = ($after ?? CarbonImmutable::now())->setTimezone($timezone);
        [$hour, $minute] = array_map('intval', explode(':', $schedule->run_at) + [0, 0]);

        $candidate = match ($schedule->frequency) {
            'daily' => $now->setTime($hour, $minute),
            'weekly' => $now->startOfWeek(CarbonImmutable::MONDAY)->addDays(max(1, min(7, (int) $schedule->day_of_week)) - 1)->setTime($hour, $minute),
            default => self::monthDay($now, (int) ($schedule->day_of_month ?: 1))->setTime($hour, $minute),
        };

        while ($candidate->lte($now)) {
            $candidate = match ($schedule->frequency) {
                'daily' => $candidate->addDay(),
                'weekly' => $candidate->addWeek(),
                default => self::monthDay($candidate->startOfMonth()->addMonthNoOverflow(), (int) ($schedule->day_of_month ?: 1))->setTime($hour, $minute),
            };
        }

        return $candidate->utc();
    }

    private static function monthDay(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->startOfMonth()->addDays(min($day, $month->daysInMonth) - 1);
    }

    public static function describe(ReportSchedule $schedule): string
    {
        $days = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

        return match ($schedule->frequency) {
            'daily' => "Daily at {$schedule->run_at}",
            'weekly' => 'Every '.($days[(int) $schedule->day_of_week] ?? 'Monday')." at {$schedule->run_at}",
            default => 'Monthly on day '.($schedule->day_of_month ?: 1)." at {$schedule->run_at}",
        };
    }
}
