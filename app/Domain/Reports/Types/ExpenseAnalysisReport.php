<?php

namespace App\Domain\Reports\Types;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;

/** Operating expenses by category with share and change vs the previous period. */
final class ExpenseAnalysisReport extends ReportType
{
    public function key(): string
    {
        return 'expense_analysis';
    }

    public function label(): string
    {
        return 'Expense analysis';
    }

    public function description(): string
    {
        return 'Operating expenses by category, share of total and change against the previous period.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('category', 'Expense category'),
            new ReportColumn('amount', 'Amount', 'currency'),
            new ReportColumn('share', 'Share', 'percent'),
            new ReportColumn('previous', 'Previous period', 'currency'),
            new ReportColumn('change', 'Change', 'percent'),
        ];
    }

    public function filters(): array
    {
        return ['branches'];
    }

    public function note(): string
    {
        return 'With a branch filter, head-office costs (not attributed to a branch) are excluded.';
    }

    protected function rows(ReportParameters $p): array
    {
        $rows = $this->engine->breakdown($p->range, $p->filters, 'expense_category', ['operating_expenses'], orderBy: 'operating_expenses', compare: true);
        $total = array_sum(array_map(fn ($r) => $r['values']['operating_expenses'] ?? 0, $rows));

        return array_map(fn ($r) => [
            'category' => $r['label'],
            'amount' => $r['values']['operating_expenses'],
            'share' => $total > 0 ? round(($r['values']['operating_expenses'] ?? 0) / $total * 100, 2) : null,
            'previous' => $r['previous']['operating_expenses'] ?? null,
            'change' => MetricRegistry::change($r['values']['operating_expenses'], $r['previous']['operating_expenses'] ?? null),
        ], $rows);
    }
}
