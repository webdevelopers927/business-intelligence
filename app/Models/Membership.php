<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership in an organization (organization_user pivot).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property Role $role
 * @property Carbon|null $joined_at
 * @property-read User $user
 * @property-read Organization $organization
 */
class Membership extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected $fillable = ['organization_id', 'user_id', 'role', 'joined_at'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
