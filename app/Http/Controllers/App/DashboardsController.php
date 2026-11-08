<?php

namespace App\Http\Controllers\App;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Dashboards\DashboardService;
use App\Domain\Dashboards\WidgetConfig;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Support\AnalyticsQuery;
use App\Models\Dashboard;
use App\Models\Membership;
use App\Models\Metric;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardsController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Dashboard::class);
        /** @var User $user */
        $user = $request->user();

        $rows = Dashboard::query()
            ->visibleTo($user, $this->seesAll())
            ->with(['owner:id,name'])
            ->withCount('widgets')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Dashboard $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'visibility' => $d->visibility,
                'owner' => $d->owner?->name,
                'is_mine' => $d->owner_id === $user->id,
                'widgets' => $d->widgets_count,
                'updated_at' => $d->updated_at?->toIso8601String(),
                'can' => ['duplicate' => $user->can('duplicate', $d), 'delete' => $user->can('delete', $d)],
            ]);

        return Inertia::render('dashboards/Index', [
            'dashboards' => $rows,
            'canCreate' => $user->can('create', Dashboard::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Dashboard::class);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'visibility' => ['required', Rule::in(['private', 'organization'])],
        ]);
        /** @var User $user */
        $user = $request->user();

        $dashboard = $this->dashboards->create($user, trim($data['name']), $data['description'] ?? null, $data['visibility']);

        return redirect()->route('dashboards.show', ['dashboard' => $dashboard, 'edit' => 1]);
    }

    public function show(Request $request, Dashboard $dashboard, MetricRegistry $registry): Response
    {
        Gate::authorize('view', $dashboard);
        /** @var User $user */
        $user = $request->user();
        $q = AnalyticsQuery::fromRequest($request, $this->tenant);
        $dashboard->load(['widgets', 'owner:id,name', 'shares.user:id,name,email']);
        $canShare = $user->can('share', $dashboard);

        $usableCustom = Metric::query()->usableBy($user)->pluck('key')->all();
        $metrics = collect($registry->all())
            ->filter(fn ($m) => ! $m->isCustom() || in_array($m->key, $usableCustom, true))
            ->map(fn ($m) => [
                ...$m->toArray(),
                'dimensions' => array_values(array_filter(array_keys(WidgetConfig::DIMENSIONS), fn ($d) => $registry->supports($m->key, $d))),
            ])
            ->values();

        return Inertia::render('dashboards/Show', [
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'description' => $dashboard->description,
                'visibility' => $dashboard->visibility,
                'owner' => $dashboard->owner?->name,
                'updated_at' => $dashboard->updated_at?->toIso8601String(),
                'can' => [
                    'update' => $user->can('update', $dashboard),
                    'delete' => $user->can('delete', $dashboard),
                    'share' => $canShare,
                    'duplicate' => $user->can('duplicate', $dashboard),
                ],
            ],
            'widgets' => $dashboard->widgets->map(fn ($w) => [...$w->toClient(), ...array_intersect_key(WidgetConfig::size($w->type), ['min_w' => 1, 'min_h' => 1])])->values(),
            'filters' => $q->state(),
            'options' => AnalyticsQuery::options($this->tenant),
            'period' => [
                'current' => $q->range->toArray(),
                'previous' => $q->range->previous()->toArray(),
                'preset_label' => $q->range->presetLabel(),
                'granularity' => $q->range->granularity()->value,
                'data_through' => null,
            ],
            'catalog' => [
                'metrics' => $metrics,
                'dimensions' => collect(WidgetConfig::DIMENSIONS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
                'types' => collect(WidgetConfig::TYPES)->map(fn ($t, $key) => ['value' => $key, 'dimensions' => $t[0]])->values(),
            ],
            'sharing' => $canShare ? [
                'members' => Membership::query()
                    ->where('organization_id', $this->tenant->id())
                    ->where('user_id', '!=', $dashboard->owner_id)
                    ->with('user:id,name,email')
                    ->get()
                    ->map(fn (Membership $m) => ['id' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role->value])
                    ->values(),
                'shares' => $dashboard->shares->map(fn ($s) => ['user_id' => $s->user_id, 'permission' => $s->permission])->values(),
            ] : null,
            'startEditing' => $request->boolean('edit') && $user->can('update', $dashboard),
        ]);
    }

    public function update(Request $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $this->dashboards->update($dashboard, trim($data['name']), $data['description'] ?? null);
        $this->toast('Dashboard renamed.');

        return back();
    }

    public function destroy(Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('delete', $dashboard);
        $this->dashboards->delete($dashboard);
        $this->toast("{$dashboard->name} deleted.");

        return redirect()->route('dashboards.index');
    }

    public function duplicate(Request $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('duplicate', $dashboard);
        /** @var User $user */
        $user = $request->user();
        $dashboard->load('widgets');

        $copy = $this->dashboards->duplicate($dashboard, $user);
        $this->toast("Created {$copy->name}.");

        return redirect()->route('dashboards.show', $copy);
    }

    public function layout(Request $request, Dashboard $dashboard): JsonResponse
    {
        Gate::authorize('update', $dashboard);
        $data = $request->validate([
            'items' => ['present', 'array', 'max:60'],
            'items.*.id' => ['required', 'integer'],
            'items.*.x' => ['required', 'integer', 'min:0'],
            'items.*.y' => ['required', 'integer', 'min:0'],
            'items.*.w' => ['required', 'integer', 'min:1'],
            'items.*.h' => ['required', 'integer', 'min:1'],
        ]);

        $this->dashboards->saveLayout($dashboard, $data['items']);

        return response()->json(['data' => ['saved_at' => $dashboard->fresh()?->updated_at?->toIso8601String()]]);
    }

    public function share(Request $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('share', $dashboard);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['private', 'organization', 'shared'])],
            'shares' => ['array', 'max:200'],
            'shares.*.user_id' => ['required', 'integer'],
            'shares.*.permission' => ['required', Rule::in(['view', 'edit'])],
        ]);

        $this->dashboards->share($dashboard, $data['visibility'], $data['shares'] ?? []);
        $this->toast('Sharing updated.');

        return back();
    }

    private function seesAll(): bool
    {
        return in_array($this->tenant->role(), [Role::Owner, Role::Admin], true);
    }
}
