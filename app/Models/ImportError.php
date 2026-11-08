<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Child of Import (reached only through a tenant-scoped Import).
 *
 * @property int $row_number
 * @property string|null $field
 * @property string $code
 * @property string $message
 * @property array<string, string>|null $raw
 */
class ImportError extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw' => 'array'];
    }
}
