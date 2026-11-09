<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only audit trail for owners and administrators. Entries are written
 * already redacted (AuditLogger); this screen never shows raw secrets.
 */
final class ActivityController extends Controller
{
    private const AREAS = [
        'auth' => 'Sign-ins',
        'organization' => 'Organization',
        'member' => 'Members',
        'data_source' => 'Data sources',
        'import' => 'Imports',
        'dashboard' => 'Dashboards',
        'metric' => 'Metrics',
        'report' => 'Reports',
        'alert' => 'Alerts',
    ];

    public function __invoke(Request $request, TenantContext $tenant): Response
    {
        Gate::authorize(Permission::AuditView->value);

        $filters = $request->validate([
            'area' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::AREAS))],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->forOrganization($tenant->id())
            ->with('user:id,name,email')
            ->when($filters['area'] ?? null, fn ($q, $a) => $q->where('action', 'like', $a.'.%'))
            ->when($filters['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('activity/Index', [
            'logs' => [
                'data' => collect($logs->items())->map(fn (AuditLog $l) => [
                    'id' => $l->id,
                    'action' => $l->action,
                    'area' => self::AREAS[strtok($l->action, '.')] ?? 'Other',
                    'actor' => $l->user->name ?? 'System',
                    'actor_email' => $l->user?->email,
                    'subject' => $l->subject_type ? str_replace('_', ' ', $l->subject_type).($l->subject_id ? " #{$l->subject_id}" : '') : null,
                    'metadata' => $l->metadata,
                    'ip' => $l->ip_address,
                    'created_at' => $l->created_at->toIso8601String(),
                ]),
                'meta' => [
                    'current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(),
                    'total' => $logs->total(), 'from' => $logs->firstItem(), 'to' => $logs->lastItem(),
                ],
            ],
            'filters' => $filters,
            'areas' => collect(self::AREAS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'members' => Membership::query()->where('organization_id', $tenant->id())->with('user:id,name')->get()
                ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user->name])->values(),
        ]);
    }
}
