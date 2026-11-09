<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Tenancy\TenantContext;
use App\Models\Alert;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes. Finds due alerts across organizations (an
 * explicit, grep-able tenancy bypass) and evaluates each inside its own
 * organization's tenant context.
 */
class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate active alerts that are due';

    public function handle(TenantContext $tenant): int
    {
        $now = CarbonImmutable::now();
        $due = $tenant->bypass(fn () => Alert::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_evaluation_at')->orWhere('next_evaluation_at', '<=', $now))
            ->orderBy('organization_id')
            ->get(['id', 'organization_id']));

        $count = 0;
        foreach ($due->groupBy('organization_id') as $orgId => $alerts) {
            $organization = Organization::query()->find($orgId);
            if ($organization === null) {
                continue;
            }

            $tenant->runAs($organization, function () use ($alerts, $now, &$count) {
                foreach (Alert::query()->whereKey($alerts->pluck('id'))->get() as $alert) {
                    app(AlertEvaluator::class)->evaluate($alert, $now);
                    $count++;
                }
            });
        }

        $this->info("Evaluated {$count} alert(s).");

        return self::SUCCESS;
    }
}
