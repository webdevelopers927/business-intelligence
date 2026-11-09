<?php

namespace App\Domain\Reports\Types;

use App\Domain\Analytics\Metrics\MetricRegistry;
use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;

/** Revenue per period bucket (day/week/month by range length) vs the previous period. */
final class RevenueAnalysisReport extends ReportType
{
    public function key(): string
    {
        return 'revenue_analysis';
    }

    public function label(): string
    {
        return 'Revenue analysis';
    }

    public function description(): string
    {
        return 'Revenue, orders and average order value per period, compared with the previous period.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('period', 'Period'),
            new ReportColumn('revenue', 'Revenue', 'currency'),
            new ReportColumn('orders', 'Orders', 'number'),
            new ReportColumn('average_order_value', 'Avg. order value', 'currency'),
            new ReportColumn('gross_margin', 'Gross margin', 'percent'),
            new ReportColumn('previous_period', 'Compared with'),
            new ReportColumn('previous_revenue', 'Previous revenue', 'currency'),
            new ReportColumn('change', 'Change', 'percent'),
        ];
    }

    public function defaultSort(): array
    {
        return ['index', 'asc'];
    }

    protected function rows(ReportParameters $p): array
    {
        $s = $this->engine->series($p->range, $p->filters, ['revenue', 'orders', 'average_order_value', 'gross_margin'], compare: true);
        $rows = [];

        foreach ($s['keys'] as $i => $key) {
            $revenue = $s['series']['revenue'][$i];
            $previous = $s['previous']['revenue'][$i] ?? null;
            $rows[] = [
                'index' => $i,
                'period' => $s['labels'][$i],
                'revenue' => $revenue,
                'orders' => $s['series']['orders'][$i],
                'average_order_value' => $s['series']['average_order_value'][$i],
                'gross_margin' => $s['series']['gross_margin'][$i],
                'previous_period' => $s['previous_labels'][$i] ?? null,
                'previous_revenue' => $previous,
                'change' => MetricRegistry::change($revenue, $previous),
            ];
        }

        return $rows;
    }
}
