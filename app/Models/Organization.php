<?php

namespace App\Models;

use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Role;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenant. Not itself tenant-scoped: access is controlled through
 * memberships (ResolveTenant middleware) rather than a global scope.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Industry $industry
 * @property OrganizationSize $size
 * @property string $timezone
 * @property string $currency
 * @property int $fiscal_year_start_month
 * @property Carbon|null $created_at
 * @property-read Membership $membership Present only when loaded through User::organizations().
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'industry',
        'size',
        'timezone',
        'currency',
        'fiscal_year_start_month',
        'onboarded_at',
    ];

    /** Mirrors the column defaults so new instances are complete before a refresh. */
    protected $attributes = [
        'timezone' => 'Asia/Karachi',
        'currency' => 'PKR',
        'fiscal_year_start_month' => 7,
    ];

    protected function casts(): array
    {
        return [
            'industry' => Industry::class,
            'size' => OrganizationSize::class,
            'fiscal_year_start_month' => 'integer',
            'onboarded_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<User, $this, Membership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function ownerCount(): int
    {
        return $this->memberships()->where('role', Role::Owner->value)->count();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
