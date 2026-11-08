<?php

namespace App\Domain\Dashboards;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DashboardService
{
    public const COLUMNS = 12;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    public function create(User $owner, string $name, ?string $description, string $visibility): Dashboard
    {
        $dashboard = Dashboard::query()->create([
            'owner_id' => $owner->id,
            'name' => $name,
            'description' => $description,
            'visibility' => $visibility === 'organization' ? 'organization' : 'private',
        ]);

        $this->audit->record('dashboard.created', $dashboard, ['name' => $name]);

        return $dashboard;
    }

    public function update(Dashboard $dashboard, string $name, ?string $description): Dashboard
    {
        $dashboard->update(['name' => $name, 'description' => $description]);
        $this->audit->record('dashboard.updated', $dashboard, ['name' => $name]);

        return $dashboard;
    }

    public function duplicate(Dashboard $source, User $owner): Dashboard
    {
        return DB::transaction(function () use ($source, $owner) {
            $copy = Dashboard::query()->create([
                'owner_id' => $owner->id,
                'name' => mb_substr($source->name.' (copy)', 0, 80),
                'description' => $source->description,
                'visibility' => 'private',
                'default_filters' => $source->default_filters,
            ]);

            foreach ($source->widgets as $widget) {
                $copy->widgets()->create($widget->only(['type', 'title', 'config', 'x', 'y', 'w', 'h']));
            }

            $this->audit->record('dashboard.duplicated', $copy, ['from' => $source->id]);

            return $copy;
        });
    }

    public function delete(Dashboard $dashboard): void
    {
        $dashboard->delete();
        $this->audit->record('dashboard.deleted', $dashboard, ['name' => $dashboard->name]);
    }

    /** @param array{type: string, title: string, config: array<string, mixed>} $widget */
    public function addWidget(Dashboard $dashboard, array $widget): DashboardWidget
    {
        $size = WidgetConfig::size($widget['type']);
        // New widgets go below everything else; the grid compacts upward.
        $bottom = (int) $dashboard->widgets()->max(DB::raw('y + h'));

        return $dashboard->widgets()->create([...$widget, 'x' => 0, 'y' => $bottom, 'w' => $size['w'], 'h' => $size['h']]);
    }

    /** @param array{type: string, title: string, config: array<string, mixed>} $data */
    public function updateWidget(DashboardWidget $widget, array $data): DashboardWidget
    {
        $size = WidgetConfig::size($data['type']);
        $widget->update([
            ...$data,
            'w' => max($widget->w, $size['min_w']),
            'h' => max($widget->h, $size['min_h']),
        ]);

        return $widget;
    }

    /**
     * Persist grid positions in one transaction. Items must all belong to
     * the dashboard; positions are clamped to the 12-column grid and each
     * widget type's minimum size.
     *
     * @param  list<array{id: int, x: int, y: int, w: int, h: int}>  $items
     */
    public function saveLayout(Dashboard $dashboard, array $items): void
    {
        DB::transaction(function () use ($dashboard, $items) {
            $widgets = $dashboard->widgets()->get()->keyBy('id');

            foreach ($items as $item) {
                $widget = $widgets[(int) $item['id']] ?? null;
                if ($widget === null) {
                    throw ValidationException::withMessages(['layout' => 'The layout refers to a widget that is not on this dashboard.']);
                }

                $size = WidgetConfig::size($widget->type);
                $w = min(self::COLUMNS, max($size['min_w'], (int) $item['w']));
                $widget->update([
                    'w' => $w,
                    'h' => min(24, max($size['min_h'], (int) $item['h'])),
                    'x' => min(self::COLUMNS - $w, max(0, (int) $item['x'])),
                    'y' => min(500, max(0, (int) $item['y'])),
                ]);
            }

            $dashboard->touch();
        });
    }

    /**
     * Set visibility and the explicit member list.
     *
     * @param  list<array{user_id: int, permission: string}>  $shares
     */
    public function share(Dashboard $dashboard, string $visibility, array $shares): Dashboard
    {
        $memberIds = Membership::query()->where('organization_id', $this->tenant->id())->pluck('user_id')->all();

        foreach ($shares as $share) {
            if (! in_array((int) $share['user_id'], $memberIds, true)) {
                throw ValidationException::withMessages(['shares' => 'Dashboards can only be shared with members of this organization.']);
            }
        }

        DB::transaction(function () use ($dashboard, $visibility, $shares) {
            $dashboard->update(['visibility' => $visibility]);
            $dashboard->shares()->delete();

            if ($visibility === 'shared') {
                foreach ($shares as $share) {
                    if ((int) $share['user_id'] !== $dashboard->owner_id) {
                        $dashboard->shares()->create([
                            'user_id' => (int) $share['user_id'],
                            'permission' => $share['permission'] === 'edit' ? 'edit' : 'view',
                        ]);
                    }
                }
            }
        });

        $this->audit->record('dashboard.shared', $dashboard, [
            'visibility' => $visibility,
            'members' => $visibility === 'shared' ? count($shares) : 0,
        ]);

        return $dashboard->load('shares');
    }
}
