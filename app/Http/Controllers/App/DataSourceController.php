<?php

namespace App\Http\Controllers\App;

use App\Domain\DataSources\DataSourceService;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataSources\SaveDataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Http\Resources\ImportResource;
use App\Http\Support\Paginated;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class DataSourceController extends Controller
{
    public function __construct(private readonly DataSourceService $sources) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', DataSource::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::enum(DataSourceType::class)],
            'status' => ['nullable', Rule::enum(DataSourceStatus::class)],
            'sort' => ['nullable', Rule::in(['name', 'last_synced_at', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $sources = DataSource::query()
            ->with(['credential:id,data_source_id', 'creator:id,name'])
            ->withCount('imports')
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.addcslashes($s, '%_').'%'))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate(Paginated::perPage($request))
            ->withQueryString();

        return Inertia::render('sources/Index', [
            'sources' => Paginated::of($sources, DataSourceResource::class, $request),
            'filters' => $filters,
            'types' => DataSourceType::options(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', DataSource::class);

        return Inertia::render('sources/Form', [
            'source' => null,
            'types' => DataSourceType::options(),
        ]);
    }

    public function store(SaveDataSourceRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $source = $this->sources->create(
            $user,
            $request->string('name')->trim()->value(),
            $request->type(),
            $request->input('description'),
            (array) $request->validated('config', []),
            (array) $request->validated('secret', []),
        );

        $this->toast($source->type->isFile()
            ? "{$source->name} is ready for uploads."
            : "{$source->name} saved. Test the connection to activate it.");

        return redirect()->route('sources.show', $source);
    }

    public function show(Request $request, DataSource $source): Response
    {
        Gate::authorize('view', $source);
        $source->load(['credential:id,data_source_id', 'creator:id,name'])->loadCount('imports');

        $imports = $source->imports()
            ->with(['creator:id,name'])
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('sources/Show', [
            'source' => (new DataSourceResource($source))->resolve($request),
            'imports' => Paginated::of($imports, ImportResource::class, $request),
        ]);
    }

    public function edit(Request $request, DataSource $source): Response
    {
        Gate::authorize('update', $source);
        $source->load('credential:id,data_source_id');

        return Inertia::render('sources/Form', [
            'source' => (new DataSourceResource($source))->resolve($request),
            'types' => DataSourceType::options(),
        ]);
    }

    public function update(SaveDataSourceRequest $request, DataSource $source): RedirectResponse
    {
        $this->sources->update(
            $source,
            $request->string('name')->trim()->value(),
            $request->input('description'),
            (array) $request->validated('config', []),
            (array) $request->validated('secret', []),
        );

        $this->toast('Data source saved.');

        return redirect()->route('sources.show', $source);
    }

    public function test(DataSource $source): JsonResponse
    {
        Gate::authorize('update', $source);
        $source->load('credential');

        $result = $this->sources->test($source);

        return response()->json([
            'data' => [
                ...$result,
                'source' => (new DataSourceResource($source->fresh(['credential:id,data_source_id'])))->resolve(),
            ],
        ]);
    }

    public function toggle(DataSource $source): RedirectResponse
    {
        Gate::authorize('update', $source);

        $source = $this->sources->toggle($source);
        $this->toast($source->status === DataSourceStatus::Disabled ? "{$source->name} disabled." : "{$source->name} enabled.");

        return back();
    }

    public function destroy(DataSource $source): RedirectResponse
    {
        Gate::authorize('delete', $source);

        $this->sources->delete($source);
        $this->toast("{$source->name} deleted. Data it imported stays available.");

        return redirect()->route('sources.index');
    }
}
