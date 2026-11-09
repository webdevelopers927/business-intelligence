<?php

namespace App\Console\Commands;

use App\Domain\Insights\AnomalyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Nightly anomaly scan. Each organization is scanned inside its own tenant
 * context; one organization's failure doesn't stop the others.
 */
class DetectAnomalies extends Command
{
    protected $signature = 'insights:detect-anomalies {--organization= : Only scan this organization (slug)}';

    protected $description = 'Detect anomalies in daily revenue and orders and monthly expenses';

    public function handle(TenantContext $tenant): int
    {
        $organizations = Organization::query()
            ->when($this->option('organization'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('id')
            ->get();

        $failed = 0;
        foreach ($organizations as $organization) {
            try {
                $result = $tenant->runAs($organization, fn () => app(AnomalyService::class)->scan());
                $this->line(sprintf('%s: %d anomalies (%d cleared)', $organization->slug, $result['detected'], $result['removed']));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$organization->slug}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
