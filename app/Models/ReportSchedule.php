<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $report_id
 * @property 'daily'|'weekly'|'monthly' $frequency
 * @property int|null $day_of_week 1 = Monday … 7 = Sunday
 * @property int|null $day_of_month
 * @property string $run_at HH:MM in the organization timezone
 * @property 'csv'|'xlsx'|'pdf' $format
 * @property list<int> $recipients
 * @property bool $is_active
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property-read Report|null $report Null when the report was soft-deleted.
 */
class ReportSchedule extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['report_id', 'frequency', 'day_of_week', 'day_of_month', 'run_at', 'format', 'recipients', 'is_active', 'next_run_at', 'last_run_at'];

    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
