<?php

namespace App\Domain\Metrics;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Audit\AuditLogger;
use App\Domain\Metrics\Formula\Formula;
use App\Domain\Metrics\Formula\FormulaException;
use App\Domain\Tenancy\TenantContext;
use App\Models\DashboardWidget;
use App\Models\Metric;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Defines, validates and previews custom metrics.
 *
 * A formula may reference base metrics and other custom metrics the author
 * can use. References must exist, may not form a cycle, and the result is
 * stored with its dependency list for impact checks.
 */
final class CustomMetricService
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly AnalyticsEngine $engine,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Parse and validate a formula for a metric with the given key.
     *
     * @return array{formula: Formula, dependencies: list<string>}
     *
     * @throws ValidationException
     */
    public function validateFormula(string $source, ?string $ownKey, User $user): array
    {
        try {
            $formula = Formula::parse($source);
        } catch (FormulaException $e) {
            throw ValidationException::withMessages(['formula' => $e->getMessage()]);
        }

        $usable = $this->usableKeys($user, $ownKey);
        $unknown = array_values(array_diff($formula->identifiers(), $usable));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'formula' => sprintf('Unknown metric%s: %s.', count($unknown) > 1 ? 's' : '', implode(', ', $unknown)),
            ]);
        }

        if ($ownKey !== null && $this->createsCycle($ownKey, $formula->identifiers())) {
            throw ValidationException::withMessages(['formula' => 'This formula refers back to itself through another metric.']);
        }

        return ['formula' => $formula, 'dependencies' => $formula->identifiers()];
    }

    /**
     * Value of a formula over a date range, plus the inputs it used — the
     * live preview in the metric editor.
     *
     * @return array{value: float|null, inputs: array<string, float|null>, range: string}
     */
    public function preview(string $source, ?string $ownKey, User $user): array
    {
        ['formula' => $formula] = $this->validateFormula($source, $ownKey, $user);

        $today = CarbonImmutable::now($this->tenant->organization()->timezone);
        $range = DateRange::fromPreset('last_30_days', $today);
        $totals = $this->engine->totals($range, new Filters);
        $inputs = array_intersect_key($totals, array_flip($formula->identifiers()));

        return ['value' => $formula->evaluate($totals), 'inputs' => $inputs, 'range' => $range->label()];
    }

    /** @param array{name: string, description?: string|null, formula: string, format: string, decimals: int, polarity: string, visibility: string} $data */
    public function create(User $user, array $data): Metric
    {
        $key = $this->uniqueKey($data['name']);
        ['formula' => $formula, 'dependencies' => $deps] = $this->validateFormula($data['formula'], $key, $user);

        $metric = Metric::query()->create([
            ...$data,
            'key' => $key,
            'formula' => $formula->normalized(),
            'dependencies' => $deps,
            'created_by' => $user->id,
        ]);

        $this->registry->refresh();
        $this->audit->record('metric.created', $metric, ['key' => $key, 'formula' => $metric->formula]);

        return $metric;
    }

    /** @param array{name: string, description?: string|null, formula: string, format: string, decimals: int, polarity: string, visibility: string} $data */
    public function update(Metric $metric, User $user, array $data): Metric
    {
        ['formula' => $formula, 'dependencies' => $deps] = $this->validateFormula($data['formula'], $metric->key, $user);

        if ($data['visibility'] === 'private' && $metric->visibility === 'organization' && $this->dependents($metric) !== []) {
            throw ValidationException::withMessages(['visibility' => 'Other metrics use this one, so it must stay visible to the organization.']);
        }

        $before = $metric->formula;
        $metric->update([...$data, 'formula' => $formula->normalized(), 'dependencies' => $deps]);

        $this->registry->refresh();
        $this->audit->record('metric.updated', $metric, ['key' => $metric->key, 'from' => $before, 'to' => $metric->formula]);

        return $metric;
    }

    public function delete(Metric $metric): void
    {
        $dependents = $this->dependents($metric);
        $widgets = $this->widgetUsage($metric->key);

        if ($dependents !== [] || $widgets > 0) {
            $reasons = [];
            if ($dependents !== []) {
                $reasons[] = 'metrics '.implode(', ', $dependents);
            }
            if ($widgets > 0) {
                $reasons[] = $widgets.' dashboard widget'.($widgets > 1 ? 's' : '');
            }

            throw ValidationException::withMessages(['metric' => 'This metric is used by '.implode(' and ', $reasons).'. Remove those uses first.']);
        }

        DB::transaction(fn () => $metric->delete());
        $this->registry->refresh();
        $this->audit->record('metric.deleted', $metric, ['key' => $metric->key]);
    }

    /** Number of widgets in this organization that reference the metric. */
    public function widgetUsage(string $key): int
    {
        return DashboardWidget::query()
            ->whereHas('dashboard')
            ->get(['config'])
            ->filter(fn (DashboardWidget $w) => ($w->config['metric'] ?? null) === $key)
            ->count();
    }

    /** @return list<string> keys of custom metrics whose formula references $metric */
    public function dependents(Metric $metric): array
    {
        return Metric::query()
            ->whereKeyNot($metric->id)
            ->get(['key', 'dependencies'])
            ->filter(fn (Metric $m) => in_array($metric->key, $m->dependencies, true))
            ->pluck('key')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function usableKeys(User $user, ?string $ownKey): array
    {
        $custom = Metric::query()->usableBy($user)
            ->when($ownKey !== null, fn ($q) => $q->where('key', '!=', $ownKey))
            ->pluck('key')
            ->all();

        return [...array_keys($this->registry->base()), ...$custom];
    }

    /** @param list<string> $dependencies */
    private function createsCycle(string $ownKey, array $dependencies): bool
    {
        $graph = Metric::query()->pluck('dependencies', 'key')->all();
        $graph[$ownKey] = $dependencies;
        $stack = $dependencies;
        $seen = [];

        while ($stack !== []) {
            $key = array_pop($stack);
            if ($key === $ownKey) {
                return true;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($graph[$key] ?? [] as $next) {
                $stack[] = $next;
            }
        }

        return false;
    }

    private function uniqueKey(string $name): string
    {
        // slug(), not snake(): snake('COGS share') would give "c_o_g_s_share".
        $base = trim(Str::limit(Str::slug($name, '_'), 40, ''), '_') ?: 'metric';
        if (isset($this->registry->base()[$base]) || ctype_digit($base[0])) {
            $base = 'custom_'.$base;
        }

        $key = $base;
        $n = 2;
        while (Metric::withTrashed()->where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }
}
