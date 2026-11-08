<?php

namespace App\Http\Controllers\App;

use App\Domain\Imports\ImportService;
use App\Domain\Imports\ValueParser;
use App\Enums\DatasetType;
use App\Enums\DataSourceStatus;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ImportResource;
use App\Http\Support\Paginated;
use App\Models\DataSource;
use App\Models\Import;
use App\Models\ImportError;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Import::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(ImportStatus::class)],
            'dataset' => ['nullable', Rule::enum(DatasetType::class)],
        ]);

        $imports = Import::query()
            ->with(['dataSource:id,name', 'creator:id,name'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('original_filename', 'like', '%'.addcslashes($s, '%_').'%'))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['dataset'] ?? null, fn ($q, $d) => $q->where('dataset_type', $d))
            ->latest()
            ->orderByDesc('id')
            ->paginate(Paginated::perPage($request))
            ->withQueryString();

        return Inertia::render('imports/Index', [
            'imports' => Paginated::of($imports, ImportResource::class, $request),
            'filters' => $filters,
            'datasets' => DatasetType::options(),
            'statuses' => array_map(fn (ImportStatus $s) => ['value' => $s->value, 'label' => $s->label()], ImportStatus::cases()),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Import::class);

        return Inertia::render('imports/Create', [
            'datasets' => DatasetType::options(),
            'sources' => DataSource::query()
                ->whereIn('type', ['csv', 'xlsx'])
                ->where('status', DataSourceStatus::Active->value)
                ->orderBy('name')
                ->get(['id', 'name', 'type'])
                ->map(fn (DataSource $s) => ['value' => (string) $s->id, 'label' => $s->name]),
            'defaults' => [
                'dataset_type' => $request->query('dataset'),
                'data_source_id' => $request->query('source'),
            ],
            'maxUploadMb' => (int) round(config('orbit.imports.max_upload_kb') / 1024),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Import::class);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'extensions:csv,xlsx', 'max:'.config('orbit.imports.max_upload_kb')],
            'dataset_type' => ['required', Rule::enum(DatasetType::class)],
            'data_source_id' => ['nullable', 'integer'],
        ], [
            'file.mimes' => 'Upload a CSV or Excel (.xlsx) file.',
            'file.extensions' => 'Upload a CSV or Excel (.xlsx) file.',
        ]);

        $source = null;
        if (! empty($validated['data_source_id'])) {
            // Tenant-scoped lookup: another organization's source id is simply not found.
            $source = DataSource::query()
                ->whereIn('type', ['csv', 'xlsx'])
                ->where('status', DataSourceStatus::Active->value)
                ->find($validated['data_source_id']);

            if ($source === null) {
                return back()->withErrors(['data_source_id' => 'Choose an active file source.']);
            }
        }

        /** @var User $user */
        $user = $request->user();
        $import = $this->imports->upload($request->file('file'), DatasetType::from($validated['dataset_type']), $source, $user);

        return redirect()->route('imports.mapping', $import);
    }

    public function mapping(Import $import): Response|RedirectResponse
    {
        Gate::authorize('update', $import);

        if ($import->status !== ImportStatus::Draft) {
            return redirect()->route('imports.show', $import);
        }

        return Inertia::render('imports/Mapping', [
            'import' => (new ImportResource($import))->resolve(),
            'headers' => $import->headers,
            ...$this->imports->mappingContext($import),
            'dateFormats' => array_map(
                fn (string $format, string $example) => ['value' => $format, 'label' => $example],
                array_keys(ValueParser::DATE_FORMATS),
                ValueParser::DATE_FORMATS,
            ),
        ]);
    }

    public function confirm(Request $request, Import $import): RedirectResponse
    {
        Gate::authorize('update', $import);

        $validated = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'date_format' => ['required', 'string'],
        ]);

        $this->imports->confirm($import, $validated['mapping'], $validated['date_format']);

        return redirect()->route('imports.show', $import);
    }

    public function show(Request $request, Import $import): Response
    {
        Gate::authorize('view', $import);
        $import->load(['dataSource:id,name', 'creator:id,name']);

        $errors = $import->errors()
            ->orderBy('row_number')
            ->paginate(25, ['id', 'row_number', 'field', 'code', 'message'], 'errors_page')
            ->withQueryString();

        return Inertia::render('imports/Show', [
            'import' => (new ImportResource($import))->resolve($request),
            'mappings' => $import->mappings()->orderBy('id')->get(['source_column', 'target_field']),
            'errors' => [
                'data' => $errors->items(),
                'meta' => [
                    'current_page' => $errors->currentPage(),
                    'last_page' => $errors->lastPage(),
                    'total' => $errors->total(),
                    'from' => $errors->firstItem(),
                    'to' => $errors->lastItem(),
                    'per_page' => $errors->perPage(),
                ],
            ],
            'errorSummary' => $import->errors()
                ->selectRaw('code, count(*) as total')
                ->groupBy('code')
                ->orderByDesc('total')
                ->pluck('total', 'code'),
        ]);
    }

    /** Failed rows as CSV: the reason first, then the row exactly as uploaded. */
    public function errors(Import $import): StreamedResponse
    {
        Gate::authorize('view', $import);

        $filename = pathinfo($import->original_filename, PATHINFO_FILENAME).'-failed-rows.csv';

        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens it correctly
            fputcsv($out, ['Row', 'Field', 'Error code', 'Message', ...$import->headers], escape: '');

            $import->errors()->orderBy('row_number')->orderBy('id')->chunk(1000, function ($errors) use ($out, $import) {
                foreach ($errors as $error) {
                    /** @var ImportError $error */
                    $raw = $error->raw ?? [];
                    fputcsv($out, [
                        $error->row_number,
                        $error->field,
                        $error->code,
                        $error->message,
                        ...array_map(fn ($h) => $this->safeCell($raw[$h] ?? ''), $import->headers),
                    ], escape: '');
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralize spreadsheet formula injection in exported user data. */
    private function safeCell(?string $value): string
    {
        $value ??= '';

        if (is_numeric($value)) {
            return $value;
        }

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
