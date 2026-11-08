<?php

namespace App\Jobs;

use App\Domain\Imports\ImportProcessor;
use App\Domain\Tenancy\RestoreTenantContext;
use App\Domain\Tenancy\TenantAware;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import;
use App\Models\Organization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one import in the background. Carries ids only (no models), so
 * the tenant context is restored by job middleware before any query runs.
 */
class ProcessImport implements ShouldQueue, TenantAware
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $organization,
        public readonly int $importId,
    ) {}

    public function organizationId(): int
    {
        return $this->organization;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RestoreTenantContext];
    }

    public function handle(ImportProcessor $processor): void
    {
        $import = Import::query()->find($this->importId);

        if ($import === null || ! $import->status->isActive()) {
            return; // deleted, or already finished by an earlier attempt
        }

        $processor->process($import);
    }

    /** Final failure after all retries: mark the import failed and notify. */
    public function failed(Throwable $e): void
    {
        $organization = Organization::query()->find($this->organization);
        if ($organization === null) {
            return;
        }

        app(TenantContext::class)->runAs($organization, function () use ($e) {
            $import = Import::query()->find($this->importId);
            if ($import !== null && $import->status->isActive()) {
                app(ImportProcessor::class)->fail($import, $e);
            }
        });
    }
}
