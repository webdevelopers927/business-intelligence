<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One generated export (direct download, queued, or scheduled).
 *
 * @property int $id
 * @property int $organization_id
 * @property int|null $report_id
 * @property string $type
 * @property string $name
 * @property array<string, mixed> $parameters
 * @property 'csv'|'xlsx'|'pdf' $format
 * @property 'manual'|'queued'|'schedule' $trigger
 * @property 'queued'|'running'|'completed'|'failed' $status
 * @property int|null $row_count
 * @property string|null $file_disk
 * @property string|null $file_path
 * @property string|null $error
 * @property int|null $requested_by
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read User|null $requester
 */
class ReportRun extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'queued', 'trigger' => 'manual'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function filename(): string
    {
        $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $this->name) ?? 'report', '-') ?: 'report';

        // Stamp in the organization's local time, matching what users see in the app.
        $stamp = $this->created_at?->copy()->setTimezone($this->organization->timezone ?? config('app.timezone'))->format('Ymd-Hi');

        return strtolower($base).'-'.$stamp.'.'.$this->format;
    }
}
