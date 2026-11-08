<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
class ProductCategory extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];
}
