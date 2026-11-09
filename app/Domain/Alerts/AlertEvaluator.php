<?php

namespace App\Domain\Alerts;

use App\Domain\Analytics\AnalyticsEngine;
use App\Domain\Analytics\Metrics\MetricDefinition;
use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Tenancy\TenantContext;
use App\Models\Alert;
use App\Models\AlertExecution;
use App\Models\DataSource;
use App\Models\Import;
use App\Models\User;
use App\Notifications\AlertTriggered;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Evaluates one alert and records the outcome (docs/ARCHITECTURE.md §10).
 *
 * State machine: ok → triggered notifies recipients once; staying triggered
 * records an execution without notifying (no repeated alerts for the same
 * unresolved condition); triggered → ok records a resolution.
 *
 * Metric windows use complete days so a partial "today" never trips an alert:
 *   day   = yesterday
 *   week  = the 7 days ending yesterday
 *   month = the 30 days ending yesterday
 * Percentage conditions compare against the immediately preceding window.
 */
final class AlertEvaluator
{
    public const CONDITIONS = [
        'below' => 'falls below',
        'above' => 'rises above',
        'drops_by_pct' => 'drops by more than',
        'rises_by_pct' => 'rises by more than',
    ];

    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly MetricRegistry $metrics,
        private readonly TenantContext $tenant,
    ) {}

    public function evaluate(Alert $alert, ?CarbonImmutable $now = null): AlertExecution
    {
        $now ??= CarbonImmutable::now();

        try {
            $result = match ($alert->kind) {
                'metric' => $this->metric($alert, $now),
                'import_failed' => $this->importFailures($alert, $now),
                'source_stale' => $this->staleSources($alert, $now),
            };
        } catch (Throwable $e) {
            report($e);
            $result = ['breached' => null, 'value' => null, 'message' => 'Evaluation failed: '.mb_substr($e->getMessage(), 0, 200), 'details' => []];
        }

        return DB::transaction(function () use ($alert, $now, $result) {
            $previous = $alert->state;
            $breached = $result['breached'];

            $outcome = match (true) {
                $breached === null => 'error',
                $breached => 'triggered',
                $previous === 'triggered' => 'resolved',
                default => 'ok',
            };

            // Notify only on the transition into the triggered state.
            $notify = $breached === true && $previous !== 'triggered';

            $execution = $alert->executions()->create([
                'evaluated_at' => $now,
                'outcome' => $outcome,
                'observed_value' => $result['value'],
                'threshold' => $alert->threshold,
                'notified' => $notify,
                'message' => $result['message'],
                'details' => $result['details'],
            ]);

            $alert->forceFill([
                'state' => $breached === null ? $previous : ($breached ? 'triggered' : 'ok'),
                'last_value' => $result['value'],
                'last_evaluated_at' => $now,
                'last_triggered_at' => $notify ? $now : $alert->last_triggered_at,
                'next_evaluation_at' => self::nextEvaluation($alert->frequency, $now),
            ])->save();

            if ($notify) {
                $recipients = User::query()
                    ->whereIn('id', $alert->recipients)
                    ->whereHas('organizations', fn ($q) => $q->where('organizations.id', $alert->organization_id))
                    ->get();
                Notification::send($recipients, new AlertTriggered($alert, $execution));
            }

            return $execution;
        });
    }

    public static function nextEvaluation(string $frequency, CarbonImmutable $from): CarbonImmutable
    {
        return match ($frequency) {
            'hourly' => $from->addHour(),
            'weekly' => $from->addWeek(),
            default => $from->addDay(),
        };
    }

    public static function window(string $window, CarbonImmutable $today): DateRange
    {
        $yesterday = $today->subDay();

        return match ($window) {
            'day' => DateRange::custom($yesterday, $yesterday),
            'week' => DateRange::custom($yesterday->subDays(6), $yesterday),
            default => DateRange::custom($yesterday->subDays(29), $yesterday),
        };
    }

    /** @return array{breached: bool, value: float|null, message: string, details: array<string, mixed>} */
    private function metric(Alert $alert, CarbonImmutable $now): array
    {
        $metric = $this->metrics->get((string) $alert->metric_key);
        $today = $now->setTimezone($this->tenant->organization()->timezone)->startOfDay();
        $range = self::window((string) $alert->window, $today);
        $filters = new Filters(branchIds: $alert->filters['branches'] ?? [], categoryIds: $alert->filters['categories'] ?? []);
        $threshold = (float) $alert->threshold;

        $current = $this->engine->totals($range, $filters)[$metric->key] ?? null;
        $previous = in_array($alert->condition, ['drops_by_pct', 'rises_by_pct'], true)
            ? ($this->engine->totals($range->previous(), $filters)[$metric->key] ?? null)
            : null;
        $change = MetricRegistry::change($current, $previous);

        [$breached, $observed] = match ($alert->condition) {
            'below' => [$current !== null && $current < $threshold, $current],
            'above' => [$current !== null && $current > $threshold, $current],
            'drops_by_pct' => [$change !== null && $change <= -$threshold, $change],
            'rises_by_pct' => [$change !== null && $change >= $threshold, $change],
            default => throw new \InvalidArgumentException("Unknown alert condition [{$alert->condition}]."),
        };

        $pct = in_array($alert->condition, ['drops_by_pct', 'rises_by_pct'], true);
        $value = fn (float $v): string => match ($metric->format) {
            MetricDefinition::PERCENT => number_format($v, 1).'%',
            MetricDefinition::CURRENCY => $this->tenant->organization()->currency.' '.number_format($v, abs($v) >= 1000 ? 0 : 2),
            default => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2),
        };

        $movement = match (true) {
            $observed === null => 'could not be compared with',
            $observed < 0 => 'fell '.number_format(abs($observed), 1).'% versus',
            default => 'rose '.number_format($observed, 1).'% versus',
        };

        $message = $pct
            ? sprintf(
                '%s for %s %s the previous period (alert when it %s %s%%).',
                $metric->label,
                $range->label(),
                $movement,
                self::CONDITIONS[$alert->condition],
                number_format($threshold, 1),
            )
            : sprintf(
                '%s for %s was %s (alert when it %s %s).',
                $metric->label,
                $range->label(),
                $current === null ? 'unavailable (no data)' : $value($current),
                self::CONDITIONS[$alert->condition],
                $value($threshold),
            );

        return [
            'breached' => $breached,
            'value' => $observed === null ? null : round($observed, 4),
            'message' => $message,
            'details' => ['range' => $range->toArray(), 'current' => $current, 'previous' => $previous],
        ];
    }

    /**
     * Any import that failed since the previous evaluation (or the alert's creation).
     *
     * @return array{breached: bool, value: float, message: string, details: array<string, mixed>}
     */
    private function importFailures(Alert $alert, CarbonImmutable $now): array
    {
        $since = $alert->last_evaluated_at ?? $alert->created_at ?? $now->subDay();
        $failed = Import::query()
            ->where('status', 'failed')
            ->where('completed_at', '>', $since)
            ->orderByDesc('completed_at')
            ->get(['id', 'original_filename', 'failure_reason']);

        return [
            'breached' => $failed->isNotEmpty(),
            'value' => (float) $failed->count(),
            'message' => $failed->isEmpty()
                ? 'No failed imports since the last check.'
                : sprintf('%d import%s failed: %s.', $failed->count(), $failed->count() > 1 ? 's' : '', $failed->pluck('original_filename')->take(3)->implode(', ')),
            'details' => ['imports' => $failed->map(fn ($i) => ['id' => $i->id, 'file' => $i->original_filename])->all()],
        ];
    }

    /**
     * Active sources with no synchronization within the threshold (hours).
     *
     * @return array{breached: bool, value: float, message: string, details: array<string, mixed>}
     */
    private function staleSources(Alert $alert, CarbonImmutable $now): array
    {
        $hours = max(1, (int) $alert->threshold);
        $cutoff = $now->subHours($hours);
        $stale = DataSource::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('last_synced_at', '<', $cutoff)->orWhere(fn ($q) => $q->whereNull('last_synced_at')->where('created_at', '<', $cutoff)))
            ->orderBy('name')
            ->get(['id', 'name', 'last_synced_at']);

        return [
            'breached' => $stale->isNotEmpty(),
            'value' => (float) $stale->count(),
            'message' => $stale->isEmpty()
                ? "All active sources synchronized within {$hours} hours."
                : sprintf('%d source%s not synchronized within %d hours: %s.', $stale->count(), $stale->count() > 1 ? 's' : '', $hours, $stale->pluck('name')->take(3)->implode(', ')),
            'details' => ['sources' => $stale->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all()],
        ];
    }
}
