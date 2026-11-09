<?php

namespace Database\Seeders;

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ScheduleCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Alert;
use App\Models\Organization;
use App\Models\Report;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Saved reports (two scheduled) and alerts for the Nexora demo, with each
 * alert evaluated once against the real demo data so its history isn't empty.
 * Idempotent by name (`php artisan db:seed --class=NexoraReportsSeeder`).
 */
class NexoraReportsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo seeders must not run in production.');
        }

        $org = Organization::query()->where('slug', NexoraDemoSeeder::SLUG)->first();
        if ($org === null) {
            $this->command?->warn('Nexora Group not found; run NexoraDemoSeeder first.');

            return;
        }

        $owner = User::query()->where('email', 'owner@nexora.test')->firstOrFail();
        $admin = User::query()->where('email', 'admin@nexora.test')->firstOrFail();
        $analyst = User::query()->where('email', 'analyst@nexora.test')->firstOrFail();

        app(TenantContext::class)->runAs($org, function () use ($org, $owner, $admin, $analyst) {
            $this->reports($org, $owner, $admin, $analyst);
            $this->alerts($owner, $admin, $analyst);
        });
    }

    private function reports(Organization $org, User $owner, User $admin, User $analyst): void
    {
        $runner = app(ReportRunner::class);
        $definitions = [
            ['Monthly branch scorecard', 'branch_comparison', ['range' => ['preset' => 'last_month']], $owner,
                ['frequency' => 'monthly', 'day_of_month' => 1, 'day_of_week' => null, 'run_at' => '08:00', 'format' => 'pdf', 'recipients' => [$owner->id, $admin->id]]],
            ['Weekly top products', 'product_performance', ['range' => ['preset' => 'last_7_days'], 'sort' => ['column' => 'revenue', 'direction' => 'desc']], $analyst,
                ['frequency' => 'weekly', 'day_of_week' => 1, 'day_of_month' => null, 'run_at' => '07:30', 'format' => 'xlsx', 'recipients' => [$analyst->id, $admin->id]]],
            ['Quarterly expense review', 'expense_analysis', ['range' => ['preset' => 'this_quarter']], $admin, null],
            ['Low stock check', 'inventory_summary', ['sort' => ['column' => 'on_hand', 'direction' => 'asc']], $analyst, null],
        ];

        foreach ($definitions as [$name, $type, $input, $author, $schedule]) {
            if (Report::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            $report = Report::query()->create([
                'name' => $name,
                'type' => $type,
                'parameters' => $runner->parameters($type, $input)->toArray(),
                'created_by' => $author->id,
            ]);

            if ($schedule !== null) {
                $s = $report->schedule()->create([...$schedule, 'is_active' => true]);
                $s->update(['next_run_at' => ScheduleCalculator::next($s, $org->timezone)]);
            }
        }
    }

    private function alerts(User $owner, User $admin, User $analyst): void
    {
        $definitions = [
            ['Weekly revenue drop', 'metric', 'revenue', 'drops_by_pct', 15, 'week', 'daily', [$owner->id, $analyst->id]],
            ['Gross margin under 30%', 'metric', 'gross_margin', 'below', 30, 'month', 'daily', [$owner->id, $admin->id]],
            ['Expense ratio above 25%', 'metric', 'expense_ratio', 'above', 25, 'month', 'weekly', [$admin->id]],
            ['Import failures', 'import_failed', null, null, null, null, 'hourly', [$admin->id, $analyst->id]],
            ['POS feed stale', 'source_stale', null, null, 48, null, 'daily', [$admin->id]],
        ];

        $evaluator = app(AlertEvaluator::class);
        foreach ($definitions as [$name, $kind, $metric, $condition, $threshold, $window, $frequency, $recipients]) {
            if (Alert::withTrashed()->where('name', $name)->exists()) {
                continue;
            }

            $alert = Alert::query()->create([
                'name' => $name,
                'kind' => $kind,
                'metric_key' => $metric,
                'condition' => $condition,
                'threshold' => $threshold,
                'window' => $window,
                'frequency' => $frequency,
                'recipients' => $recipients,
                'is_active' => true,
                'created_by' => $admin->id,
                'next_evaluation_at' => now(),
            ]);

            $evaluator->evaluate($alert, CarbonImmutable::now());
        }
    }
}
