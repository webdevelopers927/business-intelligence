<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alert_id
 * @property Carbon $evaluated_at
 * @property 'ok'|'triggered'|'resolved'|'error' $outcome
 * @property string|null $observed_value
 * @property string|null $threshold
 * @property bool $notified
 * @property string|null $message
 * @property array<string, mixed>|null $details
 */
class AlertExecution extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['evaluated_at' => 'datetime', 'notified' => 'boolean', 'details' => 'array'];
    }
}
