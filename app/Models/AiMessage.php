<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $ai_conversation_id
 * @property 'user'|'assistant' $role
 * @property string $content
 * @property list<array<string, mixed>>|null $transcript
 * @property list<array<string, mixed>>|null $evidence
 * @property string|null $stop_reason
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property Carbon|null $created_at
 */
class AiMessage extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['input_tokens' => 0, 'output_tokens' => 0];

    protected function casts(): array
    {
        return ['transcript' => 'array', 'evidence' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }
}
