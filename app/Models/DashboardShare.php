<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $dashboard_id
 * @property int $user_id
 * @property 'view'|'edit' $permission
 * @property-read User $user
 */
class DashboardShare extends Model
{
    protected $fillable = ['user_id', 'permission'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
