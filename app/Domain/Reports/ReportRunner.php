<?php

namespace App\Domain\Reports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\GenerateReportExport;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\User;
use App\Notifications\ReportReady;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Preview and export orchestration. Small exports are generated inline and
 * downloaded immediately; large ones (or scheduled runs) are queued and the
 * requester/recipients are notified when the file is ready. Every export is
 * recorded as a ReportRun so it can be re-downloaded and audited.
 */
final class ReportRunner
{
    public const DISK = 'local';

    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportExporter $exporter,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->tenant->organization()->timezone);
    }

    /** @param array<string, mixed> $input */
    public function parameters(string $type, array $input): ReportParameters
    {
        return ReportParameters::from($this->registry->get($type), $input, $this->today(), $this->tenant->id());
    }

    /** @return array{columns: list<array<string, string>>, rows: list<array<string, mixed>>, meta: array<string, int|null>, period: string, note: string|null} */
    public function preview(ReportParameters $p, int $page, int $perPage = 25): array
    {
        $type = $this->registry->get($p->type);
        $total = $type->count($p);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'columns' => array_map(fn (string $k) => $type->column($k)?->toArray(), $p->columns),
            'rows' => $type->page($p, $page, $perPage),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total ? ($page - 1) * $perPage + 1 : null,
                'to' => $total ? min($total, $page * $perPage) : null,
            ],
            'period' => $p->range->label(),
            'note' => $type->note(),
        ];
    }

    /**
     * Export now if small, otherwise queue. The returned run is either
     * completed (download immediately) or queued (a notification follows).
     *
     * @param  list<int>  $recipients
     */
    public function export(ReportParameters $p, string $format, ?User $user, ?Report $report = null, string $trigger = 'manual', array $recipients = []): ReportRun
    {
        $type = $this->registry->get($p->type);
        $this->exporter->assertExportable($type, $p, $format);

        $run = ReportRun::query()->create([
            'report_id' => $report?->id,
            'type' => $type->key(),
            'name' => $report !== null ? $report->name : $type->label(),
            'parameters' => $p->toArray(),
            'format' => $format,
            'trigger' => $trigger,
            'status' => 'queued',
            'requested_by' => $user?->id,
        ]);

        $queue = $trigger === 'schedule' || $type->count($p) > (int) config('orbit.reports.queue_threshold', 5000);

        if ($queue) {
            if ($trigger === 'manual') {
                $run->update(['trigger' => 'queued']);
            }
            GenerateReportExport::dispatch($run->organization_id, $run->id, $recipients);
        } else {
            $this->generate($run);
        }

        return $run->refresh();
    }

    /** Build the file for a run. Used inline and by the queued job. */
    public function generate(ReportRun $run): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        try {
            $type = $this->registry->get($run->type);
            $p = ReportParameters::from($type, $run->parameters, $this->today(), $this->tenant->id());
            $path = "reports/{$run->organization_id}/".Str::uuid().'.'.$run->format;
            Storage::disk(self::DISK)->makeDirectory(dirname($path));

            $rows = $this->exporter->write($type, $p, $run->format, Storage::disk(self::DISK)->path($path), $run->name);

            $run->update([
                'status' => 'completed',
                'row_count' => $rows,
                'file_disk' => self::DISK,
                'file_path' => $path,
                'completed_at' => now(),
            ]);

            $this->audit->record('report.generated', $run, [
                'type' => $run->type,
                'format' => $run->format,
                'rows' => $rows,
                'trigger' => $run->trigger,
            ], $run->organization_id, $run->requested_by);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'completed_at' => now()]);
            throw $e;
        }
    }

    /**
     * Notify the requester and any extra recipients that a queued/scheduled run finished.
     *
     * @param  list<int>  $recipients
     */
    public function notify(ReportRun $run, array $recipients): void
    {
        $ids = array_values(array_unique(array_filter([...$recipients, $run->requested_by])));
        $users = User::query()->whereIn('id', $ids)->whereHas('organizations', fn ($q) => $q->where('organizations.id', $run->organization_id))->get();

        Notification::send($users, new ReportReady($run));
    }
}
