<?php

namespace App\Http\Resources;

use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'dataset_type' => $this->dataset_type->value,
            'dataset_label' => $this->dataset_type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'size_bytes' => $this->size_bytes,
            'total_rows' => $this->total_rows,
            'processed_rows' => $this->processed_rows,
            'imported_rows' => $this->imported_rows,
            'failed_rows' => $this->failed_rows,
            'duplicate_rows' => $this->duplicate_rows,
            'progress' => $this->progressPercent(),
            'failure_reason' => $this->failure_reason,
            'source' => $this->whenLoaded('dataSource', fn () => $this->dataSource ? ['id' => $this->dataSource->id, 'name' => $this->dataSource->name] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
