<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Child of Dashboard; always reached through a tenant-scoped dashboard.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $type
 * @property string $title
 * @property array<string, mixed> $config
 * @property int $x
 * @property int $y
 * @property int $w
 * @property int $h
 * @property-read Dashboard $dashboard
 */
class DashboardWidget extends Model
{
    protected $fillable = ['type', 'title', 'config', 'x', 'y', 'w', 'h'];

    protected function casts(): array
    {
        return ['config' => 'array', 'x' => 'integer', 'y' => 'integer', 'w' => 'integer', 'h' => 'integer'];
    }

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** @return array<string, mixed> */
    public function toClient(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'config' => $this->config,
            'x' => $this->x,
            'y' => $this->y,
            'w' => $this->w,
            'h' => $this->h,
        ];
    }
}
