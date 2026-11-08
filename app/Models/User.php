<?php

namespace App\Models;

use App\Enums\Theme;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $current_organization_id
 * @property Theme $theme
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'theme',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** Mirrors the column default so new instances are complete before a refresh. */
    protected $attributes = [
        'theme' => 'system',
        'current_organization_id' => null,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'theme' => Theme::class,
        ];
    }

    /** @return BelongsToMany<Organization, $this, Membership, 'membership'> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->using(Membership::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'joined_at'])
            ->withTimestamps();
    }

    /** @return BelongsTo<Organization, $this> */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function membershipIn(Organization|int $organization): ?Membership
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return Membership::query()
            ->where('organization_id', $id)
            ->where('user_id', $this->getKey())
            ->first();
    }

    /**
     * The organization to land on after login: the last one used if the user is
     * still a member, otherwise their first membership.
     */
    public function landingOrganization(): ?Organization
    {
        if ($this->current_organization_id !== null && $this->membershipIn($this->current_organization_id)) {
            return $this->currentOrganization;
        }

        return $this->organizations()->orderBy('organization_user.created_at')->first();
    }
}
