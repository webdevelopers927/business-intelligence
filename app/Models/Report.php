<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A saved report definition (type + parameters), reusable and schedulable.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $type
 * @property array<string, mixed> $parameters
 * @property int|null $created_by
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read ReportSchedule|null $schedule
 */
class Report extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['name', 'type', 'parameters', 'created_by'];

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasOne<ReportSchedule, $this> */
    public function schedule(): HasOne
    {
        return $this->hasOne(ReportSchedule::class);
    }

    /** @return HasMany<ReportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }
}
