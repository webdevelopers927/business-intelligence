<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A custom metric: a validated formula over other metrics.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $formula
 * @property list<string> $dependencies
 * @property 'currency'|'number'|'percent' $format
 * @property int $decimals
 * @property 'up_good'|'up_bad'|'neutral' $polarity
 * @property 'private'|'organization' $visibility
 * @property int|null $created_by
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class Metric extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['key', 'name', 'description', 'formula', 'dependencies', 'format', 'decimals', 'polarity', 'visibility', 'created_by'];

    protected function casts(): array
    {
        return ['dependencies' => 'array', 'decimals' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Metrics a user may use: organization-visible ones plus their own private ones.
     *
     * @param  Builder<Metric>  $query
     */
    public function scopeUsableBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('visibility', 'organization')->orWhere('created_by', $user->id));
    }
}
