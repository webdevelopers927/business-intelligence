<?php

namespace App\Http\Controllers\App;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Metrics\CustomMetricService;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Metric;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class MetricController extends Controller
{
    public function __construct(
        private readonly CustomMetricService $metrics,
        private readonly MetricRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $custom = Metric::query()->usableBy($user)->with('creator:id,name')->orderBy('name')->get();

        return Inertia::render('metrics/Index', [
            'base' => array_values(array_map(fn ($m) => $m->toArray(), $this->registry->base())),
            'custom' => $custom->map(fn (Metric $m) => [
                'id' => $m->id,
                'key' => $m->key,
                'name' => $m->name,
                'description' => $m->description,
                'formula' => $m->formula,
                'format' => $m->format,
                'visibility' => $m->visibility,
                'created_by' => $m->creator?->name,
                'updated_at' => $m->updated_at?->toIso8601String(),
                'can_manage' => $this->canManage($user, $m),
            ]),
            'canCreate' => $user->can(Permission::MetricsManage->value),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize(Permission::MetricsManage->value);

        return $this->form($request, null);
    }

    public function edit(Request $request, Metric $metric): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $metric), 403);

        return $this->form($request, $metric);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::MetricsManage->value);
        /** @var User $user */
        $user = $request->user();

        $metric = $this->metrics->create($user, $this->validated($request));
        $this->toast("{$metric->name} is ready to use in dashboards.");

        return redirect()->route('metrics.index');
    }

    public function update(Request $request, Metric $metric): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $metric), 403);

        $this->metrics->update($metric, $user, $this->validated($request));
        $this->toast('Metric saved.');

        return redirect()->route('metrics.index');
    }

    public function destroy(Request $request, Metric $metric): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $metric), 403);

        $this->metrics->delete($metric);
        $this->toast("{$metric->name} deleted.");

        return redirect()->route('metrics.index');
    }

    /** Live validation + value for the formula editor. */
    public function preview(Request $request): JsonResponse
    {
        Gate::authorize(Permission::MetricsManage->value);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['formula' => ['required', 'string', 'max:300'], 'key' => ['nullable', 'string', 'max:48']]);

        try {
            return response()->json(['data' => $this->metrics->preview($data['formula'], $data['key'] ?? null, $user)]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->errors()['formula'][0] ?? 'Invalid formula.', 'errors' => $e->errors()], 422);
        }
    }

    private function form(Request $request, ?Metric $metric): Response
    {
        /** @var User $user */
        $user = $request->user();
        $usable = Metric::query()->usableBy($user)
            ->when($metric !== null, fn ($q) => $q->whereKeyNot($metric->id))
            ->orderBy('name')->get(['key', 'name', 'format']);

        return Inertia::render('metrics/Form', [
            'metric' => $metric?->only(['id', 'key', 'name', 'description', 'formula', 'format', 'decimals', 'polarity', 'visibility']),
            'references' => [
                ...array_values(array_map(fn ($m) => ['key' => $m->key, 'label' => $m->label, 'format' => $m->format, 'custom' => false], $this->registry->base())),
                ...$usable->map(fn (Metric $m) => ['key' => $m->key, 'label' => $m->name, 'format' => $m->format, 'custom' => true])->all(),
            ],
        ]);
    }

    /** @return array{name: string, description: string|null, formula: string, format: string, decimals: int, polarity: string, visibility: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'formula' => ['required', 'string', 'max:300'],
            'format' => ['required', Rule::in(['currency', 'number', 'percent'])],
            'decimals' => ['required', 'integer', 'between:0,4'],
            'polarity' => ['required', Rule::in(['up_good', 'up_bad', 'neutral'])],
            'visibility' => ['required', Rule::in(['private', 'organization'])],
        ]);

        return [...$data, 'name' => trim($data['name']), 'description' => $data['description'] ?? null, 'decimals' => (int) $data['decimals']];
    }

    /** Authors manage their own metrics; organization owners/admins manage all. */
    private function canManage(User $user, Metric $metric): bool
    {
        return $user->can(Permission::MetricsManage->value)
            && ($metric->created_by === $user->id || $user->can(Permission::OrgSettingsManage->value));
    }
}
