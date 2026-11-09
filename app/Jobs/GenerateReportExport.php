<?php

namespace App\Jobs;

use App\Domain\Reports\ReportRunner;
use App\Domain\Tenancy\RestoreTenantContext;
use App\Domain\Tenancy\TenantAware;
use App\Models\ReportRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Generates a large or scheduled report export in the background. */
class GenerateReportExport implements ShouldQueue, TenantAware
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    /** @param list<int> $recipients */
    public function __construct(
        public readonly int $organization,
        public readonly int $runId,
        public readonly array $recipients = [],
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

    public function handle(ReportRunner $runner): void
    {
        $run = ReportRun::query()->find($this->runId);
        if ($run === null || $run->status === 'completed') {
            return;
        }

        try {
            $runner->generate($run);
        } finally {
            // Success or failure, the people waiting for it are told.
            $runner->notify($run->refresh(), $this->recipients);
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }
}
