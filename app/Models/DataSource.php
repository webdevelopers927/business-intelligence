<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property DataSourceType $type
 * @property string|null $description
 * @property DataSourceStatus $status
 * @property array<string, mixed>|null $config
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $last_tested_at
 * @property string|null $last_error
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read DataSourceCredential|null $credential
 * @property-read User|null $creator
 */
class DataSource extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $fillable = ['name', 'type', 'description', 'status', 'config', 'created_by'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'type' => DataSourceType::class,
            'status' => DataSourceStatus::class,
            'config' => 'array',
            'last_synced_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    /** @return HasOne<DataSourceCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(DataSourceCredential::class);
    }

    /** @return HasMany<Import, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    /** @return HasMany<DataSourceSync, $this> */
    public function syncs(): HasMany
    {
        return $this->hasMany(DataSourceSync::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
