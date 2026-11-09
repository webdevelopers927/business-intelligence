<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property 'metric'|'import_failed'|'source_stale' $kind
 * @property string|null $metric_key
 * @property 'below'|'above'|'drops_by_pct'|'rises_by_pct'|null $condition
 * @property string|null $threshold
 * @property 'day'|'week'|'month'|null $window
 * @property array{branches?: list<int>, categories?: list<int>}|null $filters
 * @property 'hourly'|'daily'|'weekly' $frequency
 * @property list<int> $recipients
 * @property bool $is_active
 * @property 'ok'|'triggered' $state
 * @property string|null $last_value
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $last_triggered_at
 * @property Carbon|null $next_evaluation_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class Alert extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['name', 'kind', 'metric_key', 'condition', 'threshold', 'window', 'filters', 'frequency', 'recipients', 'is_active', 'created_by', 'next_evaluation_at'];

    protected $attributes = ['state' => 'ok', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'recipients' => 'array',
            'is_active' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_triggered_at' => 'datetime',
            'next_evaluation_at' => 'datetime',
        ];
    }

    /** @return HasMany<AlertExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(AlertExecution::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
