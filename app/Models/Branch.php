<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $city
 */
class Branch extends Model
{
    use BelongsToOrganization;

    /** Business-dataset models are written only by the importer and seeder. */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'is_active' => 'boolean'];
    }
}
