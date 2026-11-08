<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $source_column
 * @property string $target_field
 */
class ImportMapping extends Model
{
    protected $fillable = ['import_id', 'source_column', 'target_field'];
}
