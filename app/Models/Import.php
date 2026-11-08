<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\DatasetType;
use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $data_source_id
 * @property DatasetType $dataset_type
 * @property string $original_filename
 * @property string $disk
 * @property string $path
 * @property int $size_bytes
 * @property string $checksum_sha256
 * @property ImportStatus $status
 * @property list<string> $headers
 * @property array<string, mixed>|null $options
 * @property int|null $total_rows
 * @property int $processed_rows
 * @property int $imported_rows
 * @property int $failed_rows
 * @property int $duplicate_rows
 * @property string|null $failure_reason
 * @property int|null $created_by
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read DataSource|null $dataSource
 * @property-read User|null $creator
 */
class Import extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = [
        'status' => 'draft',
        'processed_rows' => 0,
        'imported_rows' => 0,
        'failed_rows' => 0,
        'duplicate_rows' => 0,
        'total_rows' => null,
        'failure_reason' => null,
        'started_at' => null,
        'completed_at' => null,
        'options' => null,
    ];

    protected function casts(): array
    {
        return [
            'dataset_type' => DatasetType::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'options' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DataSource, $this> */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<ImportMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(ImportMapping::class);
    }

    /** @return HasMany<ImportError, $this> */
    public function errors(): HasMany
    {
        return $this->hasMany(ImportError::class);
    }

    /** @return array<string, string> target field => source column */
    public function mappingArray(): array
    {
        return $this->mappings()->pluck('source_column', 'target_field')->all();
    }

    public function progressPercent(): int
    {
        if ($this->status->isFinished()) {
            return 100;
        }

        return $this->total_rows ? (int) min(99, floor($this->processed_rows / $this->total_rows * 100)) : 0;
    }
}
