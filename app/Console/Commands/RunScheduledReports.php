<?php

namespace App\Console\Commands;

use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ScheduleCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled every five minutes. Queues an export for every due report
 * schedule; recipients are notified with a download link when it is ready.
 */
class RunScheduledReports extends Command
{
    protected $signature = 'reports:run-scheduled';

    protected $description = 'Queue exports for report schedules that are due';

    public function handle(TenantContext $tenant): int
    {
        $now = CarbonImmutable::now();
        $due = $tenant->bypass(fn () => ReportSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', $now)
            ->get(['id', 'organization_id']));

        $count = 0;
        foreach ($due->groupBy('organization_id') as $orgId => $schedules) {
            $organization = Organization::query()->find($orgId);
            if ($organization === null) {
                continue;
            }

            $tenant->runAs($organization, function () use ($schedules, $organization, $now, &$count) {
                foreach (ReportSchedule::query()->with('report')->whereKey($schedules->pluck('id'))->get() as $schedule) {
                    // Advance first so a failure can't cause a tight retry loop.
                    $schedule->update(['last_run_at' => $now, 'next_run_at' => ScheduleCalculator::next($schedule, $organization->timezone, $now)]);

                    if ($schedule->report === null) {
                        continue;
                    }

                    try {
                        $runner = app(ReportRunner::class);
                        $type = app(ReportRegistry::class)->get($schedule->report->type);
                        $params = ReportParameters::from($type, $schedule->report->parameters, $runner->today(), $organization->id);
                        $runner->export($params, $schedule->format, null, $schedule->report, 'schedule', $schedule->recipients);
                        $count++;
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });
        }

        $this->info("Queued {$count} scheduled report(s).");

        return self::SUCCESS;
    }
}
