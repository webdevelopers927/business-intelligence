<?php

namespace App\Domain\Reports\Types;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;

/** Full P&L side by side per branch, with prorated target attainment. */
final class BranchComparisonReport extends ReportType
{
    public function key(): string
    {
        return 'branch_comparison';
    }

    public function label(): string
    {
        return 'Branch comparison';
    }

    public function description(): string
    {
        return 'Revenue, margins, expenses, net profit and target attainment for each branch.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('branch', 'Branch'),
            new ReportColumn('revenue', 'Revenue', 'currency'),
            new ReportColumn('gross_profit', 'Gross profit', 'currency'),
            new ReportColumn('gross_margin', 'Gross margin', 'percent'),
            new ReportColumn('operating_expenses', 'Operating expenses', 'currency'),
            new ReportColumn('net_profit', 'Net profit', 'currency'),
            new ReportColumn('profit_margin', 'Net margin', 'percent'),
            new ReportColumn('orders', 'Orders', 'number'),
            new ReportColumn('average_order_value', 'Avg. order value', 'currency'),
            new ReportColumn('target', 'Target', 'currency'),
            new ReportColumn('attainment', 'Target attainment', 'percent'),
        ];
    }

    public function filters(): array
    {
        return ['branches'];
    }

    public function note(): string
    {
        return 'Expenses include only costs attributed to each branch; head-office costs are listed separately.';
    }

    protected function rows(ReportParameters $p): array
    {
        $metrics = ['revenue', 'gross_profit', 'gross_margin', 'operating_expenses', 'net_profit', 'profit_margin', 'orders', 'average_order_value'];
        $targets = $this->engine->targets($p->range);

        return array_map(function ($r) use ($targets) {
            $target = $targets[(string) $r['id']] ?? null;
            $revenue = $r['values']['revenue'] ?? 0;

            return [
                'branch' => $r['label'],
                ...$r['values'],
                'target' => $target === null ? null : round($target, 2),
                'attainment' => $target ? round($revenue / $target * 100, 2) : null,
            ];
        }, $this->engine->breakdown($p->range, $p->filters, 'branch', $metrics));
    }
}
