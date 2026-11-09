<?php

namespace App\Http\Controllers\App;

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Analytics\Metrics\MetricDefinition;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Support\AnalyticsQuery;
use App\Models\Alert;
use App\Models\AlertExecution;
use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AlertController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MetricRegistry $metrics,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): Response
    {
        Gate::authorize(Permission::AlertsManage->value);

        return Inertia::render('alerts/Index', [
            'alerts' => Alert::query()->orderByDesc('state')->orderBy('name')->get()->map(fn (Alert $a) => [
                ...$this->summary($a),
                'last_evaluated_at' => $a->last_evaluated_at?->toIso8601String(),
                'last_triggered_at' => $a->last_triggered_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize(Permission::AlertsManage->value);

        return $this->form(null);
    }

    public function edit(Alert $alert): Response
    {
        Gate::authorize(Permission::AlertsManage->value);

        return $this->form($alert);
    }

    public function show(Request $request, Alert $alert): Response
    {
        Gate::authorize(Permission::AlertsManage->value);

        $executions = $alert->executions()->orderByDesc('evaluated_at')->orderByDesc('id')->paginate(20);

        return Inertia::render('alerts/Show', [
            'alert' => [
                ...$this->summary($alert),
                'last_evaluated_at' => $alert->last_evaluated_at?->toIso8601String(),
                'last_triggered_at' => $alert->last_triggered_at?->toIso8601String(),
                'next_evaluation_at' => $alert->next_evaluation_at?->toIso8601String(),
                'recipients' => User::query()->whereIn('id', $alert->recipients)->pluck('name')->all(),
            ],
            'executions' => [
                'data' => collect($executions->items())->map(fn (AlertExecution $e) => [
                    'id' => $e->id,
                    'evaluated_at' => $e->evaluated_at->toIso8601String(),
                    'outcome' => $e->outcome,
                    'notified' => $e->notified,
                    'message' => $e->message,
                ]),
                'meta' => [
                    'current_page' => $executions->currentPage(), 'last_page' => $executions->lastPage(), 'per_page' => $executions->perPage(),
                    'total' => $executions->total(), 'from' => $executions->firstItem(), 'to' => $executions->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(Permission::AlertsManage->value);
        /** @var User $user */
        $user = $request->user();

        $alert = Alert::query()->create([...$this->validated($request), 'created_by' => $user->id, 'next_evaluation_at' => now()]);
        $this->audit->record('alert.created', $alert, ['name' => $alert->name, 'kind' => $alert->kind]);
        $this->toast("{$alert->name} is active. It will be checked within five minutes.");

        return redirect()->route('alerts.show', $alert);
    }

    public function update(Request $request, Alert $alert): RedirectResponse
    {
        Gate::authorize(Permission::AlertsManage->value);

        $alert->update([...$this->validated($request), 'next_evaluation_at' => now()]);
        // A changed condition starts from a clean state, so a breach re-notifies.
        $alert->forceFill(['state' => 'ok'])->save();
        $this->audit->record('alert.updated', $alert, ['name' => $alert->name]);
        $this->toast('Alert saved.');

        return redirect()->route('alerts.show', $alert);
    }

    public function toggle(Alert $alert): RedirectResponse
    {
        Gate::authorize(Permission::AlertsManage->value);

        $alert->update(['is_active' => ! $alert->is_active, 'next_evaluation_at' => now()]);
        $this->audit->record($alert->is_active ? 'alert.enabled' : 'alert.disabled', $alert);
        $this->toast($alert->is_active ? "{$alert->name} enabled." : "{$alert->name} paused.");

        return back();
    }

    public function evaluate(Alert $alert, AlertEvaluator $evaluator): RedirectResponse
    {
        Gate::authorize(Permission::AlertsManage->value);

        $execution = $evaluator->evaluate($alert, CarbonImmutable::now());
        $this->toast(match ($execution->outcome) {
            'triggered' => $execution->notified ? 'Condition met — recipients notified.' : 'Condition still met (recipients were already notified).',
            'resolved' => 'Condition cleared — alert resolved.',
            'error' => 'The alert could not be evaluated. See the history for details.',
            default => 'Condition not met.',
        }, $execution->outcome === 'error' ? 'error' : 'info');

        return back();
    }

    public function destroy(Alert $alert): RedirectResponse
    {
        Gate::authorize(Permission::AlertsManage->value);

        $alert->delete();
        $this->audit->record('alert.deleted', $alert, ['name' => $alert->name]);
        $this->toast("{$alert->name} deleted.");

        return redirect()->route('alerts.index');
    }

    private function form(?Alert $alert): Response
    {
        return Inertia::render('alerts/Form', [
            'alert' => $alert?->only(['id', 'name', 'kind', 'metric_key', 'condition', 'threshold', 'window', 'filters', 'frequency', 'recipients', 'is_active']),
            'metrics' => collect($this->metrics->all())->map(fn ($m) => ['value' => $m->key, 'label' => $m->label, 'format' => $m->format])->values(),
            'members' => Membership::query()->where('organization_id', $this->tenant->id())->with('user:id,name')->get()
                ->map(fn (Membership $m) => ['value' => (string) $m->user_id, 'label' => $m->user->name])->values(),
            'options' => AnalyticsQuery::options($this->tenant),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $memberIds = Membership::query()->where('organization_id', $this->tenant->id())->pluck('user_id')->all();
        $kind = $request->input('kind');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(['metric', 'import_failed', 'source_stale'])],
            'metric_key' => [Rule::requiredIf($kind === 'metric'), 'nullable', Rule::in(array_keys($this->metrics->all()))],
            'condition' => [Rule::requiredIf($kind === 'metric'), 'nullable', Rule::in(array_keys(AlertEvaluator::CONDITIONS))],
            'threshold' => [Rule::requiredIf(in_array($kind, ['metric', 'source_stale'], true)), 'nullable', 'numeric', $kind === 'source_stale' ? 'between:1,720' : 'between:-1000000000000,1000000000000'],
            'window' => [Rule::requiredIf($kind === 'metric'), 'nullable', Rule::in(['day', 'week', 'month'])],
            'branches' => ['array'],
            'branches.*' => ['integer'],
            'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly'])],
            'recipients' => ['required', 'array', 'min:1', 'max:50'],
            'recipients.*' => ['integer', Rule::in($memberIds)],
            'is_active' => ['boolean'],
        ], ['recipients.*.in' => 'Recipients must be members of this organization.']);

        if ($kind === 'metric' && in_array($data['condition'], ['drops_by_pct', 'rises_by_pct'], true) && (float) $data['threshold'] <= 0) {
            throw ValidationException::withMessages(['threshold' => 'Enter a positive percentage, e.g. 15.']);
        }

        $branches = DB::table('branches')->where('organization_id', $this->tenant->id())->whereIn('id', array_map('intval', $data['branches'] ?? []))->pluck('id')->map(fn ($i) => (int) $i)->all();

        return [
            'name' => trim($data['name']),
            'kind' => $kind,
            'metric_key' => $kind === 'metric' ? $data['metric_key'] : null,
            'condition' => $kind === 'metric' ? $data['condition'] : null,
            'threshold' => $kind === 'import_failed' ? null : $data['threshold'],
            'window' => $kind === 'metric' ? $data['window'] : null,
            'filters' => $kind === 'metric' && $branches !== [] ? ['branches' => $branches] : null,
            'frequency' => $data['frequency'],
            'recipients' => array_values(array_map('intval', $data['recipients'])),
            'is_active' => $data['is_active'] ?? true,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Alert $a): array
    {
        $metric = $a->metric_key && $this->metrics->has($a->metric_key) ? $this->metrics->get($a->metric_key) : null;
        $windows = ['day' => 'yesterday', 'week' => 'the last 7 days', 'month' => 'the last 30 days'];
        $pct = in_array($a->condition, ['drops_by_pct', 'rises_by_pct'], true);
        $trim = fn (float $v): string => rtrim(rtrim(number_format($v, 2), '0'), '.');
        $threshold = (float) $a->threshold;
        $shown = match (true) {
            $pct, $metric?->format === MetricDefinition::PERCENT => $trim($threshold).'%',
            $metric?->format === MetricDefinition::CURRENCY => $this->tenant->organization()->currency.' '.number_format($threshold),
            default => $trim($threshold),
        };

        $condition = match ($a->kind) {
            'metric' => sprintf('%s for %s %s %s', $metric->label ?? $a->metric_key, $windows[$a->window] ?? '', AlertEvaluator::CONDITIONS[$a->condition] ?? '', $shown),
            'import_failed' => 'An import fails',
            default => 'A data source has not synchronized for '.(int) $a->threshold.' hours',
        };

        return [
            'id' => $a->id,
            'name' => $a->name,
            'kind' => $a->kind,
            'condition' => $condition,
            'metric_format' => $metric?->format,
            'frequency' => $a->frequency,
            'state' => $a->state,
            'is_active' => $a->is_active,
            'last_value' => $a->last_value === null ? null : (float) $a->last_value,
            'value_is_percent' => $pct,
        ];
    }
}
