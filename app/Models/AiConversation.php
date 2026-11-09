<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A private AI analyst thread (one user, one organization).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiConversation extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    /** @return HasMany<AiMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }
}
