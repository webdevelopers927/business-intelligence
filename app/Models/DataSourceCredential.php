<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Encrypted connection secrets. Reached only through its DataSource (which is
 * tenant-scoped); never serialized — `secret` is hidden as a second guard.
 *
 * @property int $id
 * @property int $data_source_id
 * @property array<string, string> $secret
 */
class DataSourceCredential extends Model
{
    protected $fillable = ['data_source_id', 'secret', 'rotated_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted:array',
            'rotated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
