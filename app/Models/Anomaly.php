<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A day on which a metric fell outside its expected range (AnomalyDetector).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $metric_key
 * @property Carbon $business_date
 * @property string $actual
 * @property string $expected
 * @property string $lower_bound
 * @property string $upper_bound
 * @property string $z_score
 * @property 'warning'|'critical' $severity
 * @property 'weekday'|'trailing'|'monthly' $baseline
 * @property string $explanation
 * @property array<string, mixed>|null $details
 * @property Carbon $detected_at
 */
class Anomaly extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'detected_at' => 'datetime', 'details' => 'array'];
    }
}
