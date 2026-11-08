<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property 'private'|'organization'|'shared' $visibility
 * @property array<string, mixed>|null $default_filters
 * @property Carbon|null $updated_at
 * @property Carbon|null $created_at
 * @property-read User|null $owner
 * @property-read Collection<int, DashboardWidget> $widgets
 * @property-read Collection<int, DashboardShare> $shares
 */
class Dashboard extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['owner_id', 'name', 'description', 'visibility', 'default_filters'];

    protected $attributes = ['visibility' => 'private'];

    protected function casts(): array
    {
        return ['default_filters' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('y')->orderBy('x');
    }

    /** @return HasMany<DashboardShare, $this> */
    public function shares(): HasMany
    {
        return $this->hasMany(DashboardShare::class);
    }

    public function shareFor(User $user): ?DashboardShare
    {
        return $this->relationLoaded('shares')
            ? $this->shares->firstWhere('user_id', $user->id)
            : $this->shares()->where('user_id', $user->id)->first();
    }

    /**
     * Dashboards a member can see: their own, organization-wide ones, and
     * ones shared with them. Admins and owners are handled by the policy.
     *
     * @param  Builder<Dashboard>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user, bool $seesAll = false): void
    {
        if ($seesAll) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('owner_id', $user->id)
            ->orWhere('visibility', 'organization')
            ->orWhere(fn (Builder $s) => $s->where('visibility', 'shared')->whereHas('shares', fn (Builder $h) => $h->where('user_id', $user->id))));
    }
}
