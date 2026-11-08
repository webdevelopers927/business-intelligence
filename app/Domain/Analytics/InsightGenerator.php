<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Analytics\Query\DateRange;
use App\Domain\Analytics\Query\Filters;
use App\Domain\Tenancy\TenantContext;

/**
 * Rule-based, data-backed observations for the executive dashboard.
 *
 * Every insight is computed from AnalyticsEngine results and carries the
 * figures it is based on. These are descriptive statements about recorded
 * data — not predictions — and are labelled as such in the UI.
 */
final class InsightGenerator
{
    /** Ignore movements smaller than this (percent) to avoid noise. */
    private const MIN_CHANGE = 5.0;

    public function __construct(
        private readonly AnalyticsEngine $engine,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return list<array{kind: string, tone: 'positive'|'negative'|'neutral', title: string, detail: string}>
     */
    public function forDashboard(DateRange $range, Filters $filters): array
    {
        $currency = $this->tenant->organization()->currency;
        $previous = $range->previous();
        $vs = DateRange::formatSpan($previous->from, $previous->to);
        $insights = [];

        $now = $this->engine->totals($range, $filters);
        $then = $this->engine->totals($previous, $filters);

        // 1. Overall revenue movement.
        $revenueChange = MetricRegistry::change($now['revenue'], $then['revenue']);
        if ($revenueChange !== null && abs($revenueChange) >= self::MIN_CHANGE) {
            $up = $revenueChange > 0;
            $insights[] = [
                'kind' => 'revenue_change',
                'tone' => $up ? 'positive' : 'negative',
                'title' => sprintf('Revenue %s %s', $up ? 'up' : 'down', Money::magnitude($revenueChange)),
                'detail' => sprintf('%s compared with %s for %s.', Money::compact((float) $now['revenue'], $currency), Money::compact((float) $then['revenue'], $currency), $vs),
            ];
        }

        // 2. Branch that moved the most (absolute), and its share of the total change.
        $branches = $this->engine->breakdown($range, $filters, 'branch', ['revenue'], compare: true);
        $branches = array_values(array_filter($branches, fn ($b) => $b['label'] !== 'Head office'));
        $totalDelta = (float) $now['revenue'] - (float) $then['revenue'];
        if (count($branches) > 1) {
            usort($branches, fn ($a, $b) => abs($b['values']['revenue'] - $b['previous']['revenue']) <=> abs($a['values']['revenue'] - $a['previous']['revenue']));
            $top = $branches[0];
            $delta = $top['values']['revenue'] - $top['previous']['revenue'];
            $pct = MetricRegistry::change($top['values']['revenue'], $top['previous']['revenue']);
            if ($pct !== null && abs($pct) >= self::MIN_CHANGE) {
                $share = abs($totalDelta) > 0.01 ? $delta / $totalDelta * 100 : null;
                $insights[] = [
                    'kind' => 'branch_mover',
                    'tone' => $delta >= 0 ? 'positive' : 'negative',
                    'title' => sprintf('%s %s %s', $top['label'], $delta >= 0 ? 'grew' : 'declined', Money::magnitude($pct)),
                    'detail' => sprintf(
                        'The largest branch movement: %s%s.',
                        Money::compact($delta, $currency),
                        $share !== null && $share > 0 && $share <= 200 && (($delta > 0) === ($totalDelta > 0))
                            ? sprintf(', %s of the total change', number_format($share, 0).'%')
                            : '',
                    ),
                ];
            }
        }

        // 3. Category with the strongest growth and the sharpest decline.
        $categories = $this->engine->breakdown($range, $filters, 'category', ['revenue'], compare: true);
        $moves = array_values(array_filter(array_map(fn ($c) => [
            'label' => $c['label'],
            'pct' => MetricRegistry::change($c['values']['revenue'], $c['previous']['revenue']),
            'delta' => $c['values']['revenue'] - $c['previous']['revenue'],
        ], $categories), fn ($m) => $m['pct'] !== null && abs($m['pct']) >= self::MIN_CHANGE * 2));
        usort($moves, fn ($a, $b) => $b['delta'] <=> $a['delta']);
        if ($moves !== [] && $moves[0]['delta'] > 0) {
            $m = $moves[0];
            $insights[] = ['kind' => 'category_growth', 'tone' => 'positive', 'title' => "{$m['label']} leads growth", 'detail' => sprintf('Revenue %s (%s) against the comparison period.', Money::percent($m['pct']), Money::compact($m['delta'], $currency))];
        }
        $last = end($moves);
        if ($last !== false && $last['delta'] < 0) {
            $insights[] = ['kind' => 'category_decline', 'tone' => 'negative', 'title' => "{$last['label']} is slowing", 'detail' => sprintf('Revenue %s (%s) against the comparison period.', Money::percent($last['pct']), Money::compact($last['delta'], $currency))];
        }

        // 4. Revenue target attainment (prorated to the range).
        $targets = $this->engine->targets($range);
        $targetKey = count($filters->branchIds) === 1 ? (string) $filters->branchIds[0] : ($filters->isEmpty() ? '' : null);
        if ($targetKey !== null && ($targets[$targetKey] ?? 0) > 0) {
            $attainment = (float) $now['revenue'] / $targets[$targetKey] * 100;
            $insights[] = [
                'kind' => 'target',
                'tone' => $attainment >= 100 ? 'positive' : ($attainment >= 90 ? 'neutral' : 'negative'),
                'title' => sprintf('%s%% of revenue target', number_format($attainment, 0)),
                'detail' => sprintf('%s against a prorated target of %s for this period.', Money::compact((float) $now['revenue'], $currency), Money::compact($targets[$targetKey], $currency)),
            ];
        }

        // 5. Expense ratio shift (only when expenses are fully in scope).
        if ($filters->categoryIds === [] && $now['expense_ratio'] !== null && $then['expense_ratio'] !== null) {
            $shift = $now['expense_ratio'] - $then['expense_ratio'];
            if (abs($shift) >= 2) {
                $insights[] = [
                    'kind' => 'expense_ratio',
                    'tone' => $shift > 0 ? 'negative' : 'positive',
                    'title' => sprintf('Expense ratio %s %s pts', $shift > 0 ? 'rose' : 'fell', number_format(abs($shift), 1)),
                    'detail' => sprintf('Operating expenses were %s%% of revenue, vs %s%% for %s.', number_format($now['expense_ratio'], 1), number_format($then['expense_ratio'], 1), $vs),
                ];
            }
        }

        // 6. Stock that needs attention, as of the end of the range.
        $stock = $this->engine->inventory($range->to, $filters);
        if ($stock['low_stock'] > 0) {
            $insights[] = [
                'kind' => 'low_stock',
                'tone' => 'neutral',
                'title' => sprintf('%d product %s at or below reorder level', $stock['low_stock'], $stock['low_stock'] === 1 ? 'line' : 'lines'),
                'detail' => sprintf('From the %s stock snapshot, across %d product-branch lines.', $stock['as_of'] ? date('M j', (int) strtotime($stock['as_of'])) : 'latest', $stock['lines']),
            ];
        }

        return $insights;
    }
}
