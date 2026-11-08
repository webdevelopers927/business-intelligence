<?php

namespace App\Http\Resources;

use App\Models\DataSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only shape in which a data source leaves the server. Secrets are never
 * included — the client learns only whether credentials are stored.
 *
 * @mixin DataSource
 */
class DataSourceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_file' => $this->type->isFile(),
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'config' => $this->config ?? (object) [],
            'has_credentials' => $this->whenLoaded('credential', fn () => $this->credential !== null, false),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_tested_at' => $this->last_tested_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'imports_count' => $this->whenCounted('imports'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
