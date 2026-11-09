<?php

namespace App\Http\Controllers\App;

use App\Domain\Audit\AuditLogger;
use App\Domain\Reports\ReportRegistry;
use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\ScheduleCalculator;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Support\AnalyticsQuery;
use App\Models\Membership;
use App\Models\Report;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportRegistry $registry,
        private readonly ReportRunner $runner,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize(Permission::ReportsExport->value);
        /** @var User $user */
        $user = $request->user();
        $labels = collect($this->registry->all())->mapWithKeys(fn ($t) => [$t->key() => $t->label()]);

        return Inertia::render('reports/Index', [
            'types' => $this->registry->catalog(),
            'saved' => Report::query()->with(['creator:id,name', 'schedule'])->orderBy('name')->get()->map(fn (Report $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'type' => $r->type,
                'type_label' => $labels[$r->type] ?? $r->type,
                'created_by' => $r->creator?->name,
                'schedule' => $r->schedule?->is_active ? ScheduleCalculator::describe($r->schedule) : null,
                'updated_at' => $r->updated_at?->toIso8601String(),
                'can_manage' => $this->canManage($user, $r),
            ]),
            'runs' => $this->recentRuns(),
            'canCreate' => $user->can(Permission::ReportsCreate->value),
        ]);
    }

    public function builder(Request $request, ?Report $report = null): Response
    {
        Gate::authorize(Permission::ReportsExport->value);
        /** @var User $user */
        $user = $request->user();

        $type = $report->type ?? (string) $request->query('type', 'branch_comparison');
        if (! $this->registry->has($type)) {
            $type = 'branch_comparison';
        }
        $params = $this->runner->parameters($type, $report->parameters ?? []);
        $canManage = $report !== null && $this->canManage($user, $report);

        return Inertia::render('reports/Builder', [
            'types' => $this->registry->catalog(),
            'type' => $type,
            'parameters' => $params->toArray(),
            'report' => $report === null ? null : ['id' => $report->id, 'name' => $report->name],
            'schedule' => $report?->schedule?->only(['frequency', 'day_of_week', 'day_of_month', 'run_at', 'format', 'recipients', 'is_active']),
            'scheduleDescription' => $report?->schedule ? ScheduleCalculator::describe($report->schedule) : null,
            'nextRunAt' => $report?->schedule?->next_run_at?->toIso8601String(),
            'members' => $canManage ? Membership::query()->where('organization_id', $this->tenant->id())->with('user:id,name,email')->get()
                ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user->name])->values() : [],
            'options' => AnalyticsQuery::options($this->tenant),
            'can' => [
                'save' => $user->can(Permission::ReportsCreate->value) && ($report === null || $canManage),
                'saveAs' => $user->can(Permission::ReportsCreate->value),
                'schedule' => $canManage,
                'delete' => $canManage,
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReportsExport->value);
        $data = $this->validateRequest($request);

        try {
            $params = $this->runner->parameters($data['type'], $data['parameters']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->runner->preview($params, $request->integer('page', 1))]);
    }

    public function export(Request $request): JsonResponse
    {
        Gate::authorize(Permission::ReportsExport->value);
        $data = $this->validateRequest($request, export: true);
        /** @var User $user */
        $user = $request->user();

        $report = isset($data['report_id']) ? Report::query()->find($data['report_id']) : null;

        try {
            $params = $this->runner->parameters($data['type'], $data['parameters']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $run = $this->runner->export($params, $data['format'], $user, $report);

        // Background exports always answer "queued" (the requester is notified),
        // even if a synchronous queue happened to finish the file already.
        $inline = $run->trigger === 'manual' && $run->status === 'completed';

        return response()->json(['data' => [
            'status' => $inline ? 'completed' : 'queued',
            'download_url' => $inline ? route('reports.runs.download', ['run' => $run->id]) : null,
            'message' => $inline ? null : 'This export is large, so it is being prepared in the background. You will be notified when it is ready.',
        ]]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::ReportsCreate->value);
        $data = $this->validateRequest($request, named: true);
        /** @var User $user */
        $user = $request->user();

        $params = $this->runner->parameters($data['type'], $data['parameters']);
        $report = Report::query()->create(['name' => trim($data['name']), 'type' => $data['type'], 'parameters' => $params->toArray(), 'created_by' => $user->id]);
        $this->audit->record('report.saved', $report, ['name' => $report->name, 'type' => $report->type]);
        $this->toast("Saved {$report->name}.");

        return redirect()->route('reports.show', $report);
    }

    public function update(Request $request, Report $report): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $report), 403);
        $data = $this->validateRequest($request, named: true);

        $params = $this->runner->parameters($report->type, $data['parameters']);
        $report->update(['name' => trim($data['name']), 'parameters' => $params->toArray()]);
        $this->audit->record('report.updated', $report, ['name' => $report->name]);
        $this->toast('Report saved.');

        return back();
    }

    public function destroy(Request $request, Report $report): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $report), 403);

        $report->delete();
        $this->audit->record('report.deleted', $report, ['name' => $report->name]);
        $this->toast("{$report->name} deleted.");

        return redirect()->route('reports.index');
    }

    public function schedule(Request $request, Report $report): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $report), 403);
        $memberIds = Membership::query()->where('organization_id', $this->tenant->id())->pluck('user_id')->all();

        $data = $request->validate([
            'frequency' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'day_of_week' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'required_if:frequency,monthly', 'integer', 'between:1,31'],
            'run_at' => ['required', 'date_format:H:i'],
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['integer', Rule::in($memberIds)],
            'is_active' => ['boolean'],
        ], ['recipients.*.in' => 'Recipients must be members of this organization.']);

        $schedule = $report->schedule()->updateOrCreate([], [
            ...$data,
            'recipients' => array_values(array_map('intval', $data['recipients'])),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $schedule->update(['next_run_at' => ScheduleCalculator::next($schedule, $this->tenant->organization()->timezone)]);

        $this->audit->record('report.scheduled', $report, ['schedule' => ScheduleCalculator::describe($schedule), 'recipients' => count($schedule->recipients)]);
        $this->toast('Schedule saved. Next run '.$schedule->next_run_at?->setTimezone($this->tenant->organization()->timezone)->format('M j, H:i').'.');

        return back();
    }

    public function unschedule(Request $request, Report $report): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManage($user, $report), 403);

        $report->schedule()->delete();
        $this->audit->record('report.unscheduled', $report);
        $this->toast('Schedule removed.');

        return back();
    }

    public function download(ReportRun $run): StreamedResponse
    {
        Gate::authorize(Permission::ReportsExport->value);
        abort_unless($run->status === 'completed' && $run->file_path !== null, 404);
        abort_unless(Storage::disk((string) $run->file_disk)->exists($run->file_path), 410, 'This export has expired. Generate it again.');

        return Storage::disk((string) $run->file_disk)->download($run->file_path, $run->filename());
    }

    /** @return array<string, mixed> */
    private function validateRequest(Request $request, bool $export = false, bool $named = false): array
    {
        return $request->validate([
            'type' => [$named && $request->route('report') ? 'nullable' : 'required', Rule::in(array_map(fn ($t) => $t->key(), $this->registry->all()))],
            'parameters' => ['array'],
            'parameters.range.preset' => ['nullable', 'string'],
            'parameters.range.from' => ['nullable', 'date_format:Y-m-d'],
            'parameters.range.to' => ['nullable', 'date_format:Y-m-d'],
            'parameters.filters.branches' => ['array'],
            'parameters.filters.categories' => ['array'],
            'parameters.columns' => ['array'],
            'parameters.sort.column' => ['nullable', 'string'],
            'parameters.sort.direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'format' => [$export ? 'required' : 'nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
            'report_id' => ['nullable', 'integer'],
            'name' => [$named ? 'required' : 'nullable', 'string', 'max:120'],
        ]) + ['parameters' => [], 'type' => $request->route('report')?->type];
    }

    /** @return list<array<string, mixed>> */
    private function recentRuns(): array
    {
        return ReportRun::query()->with('requester:id,name')->latest('id')->limit(10)->get()->map(fn (ReportRun $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'format' => $r->format,
            'trigger' => $r->trigger,
            'status' => $r->status,
            'row_count' => $r->row_count,
            'requested_by' => $r->requester->name ?? ($r->trigger === 'schedule' ? 'Schedule' : null),
            'created_at' => $r->created_at?->toIso8601String(),
            'download_url' => $r->status === 'completed' ? route('reports.runs.download', ['run' => $r->id]) : null,
        ])->all();
    }

    /** Authors manage their saved reports; organization owners/admins manage all. */
    private function canManage(User $user, Report $report): bool
    {
        return $user->can(Permission::ReportsCreate->value)
            && ($report->created_by === $user->id || $user->can(Permission::OrgSettingsManage->value));
    }
}
