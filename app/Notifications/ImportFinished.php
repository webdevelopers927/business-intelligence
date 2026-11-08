<?php

namespace App\Notifications;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Notifications\Channels\TenantDatabaseChannel;
use App\Notifications\Contracts\OrganizationNotification;
use Illuminate\Notifications\Notification;

/** In-app notice when an import completes or fails. */
class ImportFinished extends Notification implements OrganizationNotification
{
    public function __construct(private readonly Import $import) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return [TenantDatabaseChannel::class];
    }

    public function organizationId(): ?int
    {
        return $this->import->organization_id;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $i = $this->import;
        $imported = number_format($i->imported_rows);
        $failed = number_format($i->failed_rows);

        [$kind, $title, $body] = match ($i->status) {
            ImportStatus::Completed => ['import_completed', "Import completed: {$i->original_filename}", "{$imported} rows imported."],
            ImportStatus::CompletedWithErrors => ['import_completed', "Import completed with errors: {$i->original_filename}", "{$imported} rows imported, {$failed} rows rejected."],
            default => ['import_failed', "Import failed: {$i->original_filename}", $i->failure_reason ?? 'No rows were imported.'],
        };

        return [
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'url' => route('imports.show', ['organization' => $i->organization->slug, 'import' => $i->id]),
            'import_id' => $i->id,
        ];
    }
}
