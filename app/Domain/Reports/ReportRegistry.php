<?php

namespace App\Domain\Reports;

use App\Domain\Reports\Types\BranchComparisonReport;
use App\Domain\Reports\Types\ExpenseAnalysisReport;
use App\Domain\Reports\Types\InventorySummaryReport;
use App\Domain\Reports\Types\ProductPerformanceReport;
use App\Domain\Reports\Types\RevenueAnalysisReport;
use App\Domain\Reports\Types\SalesPerformanceReport;
use App\Domain\Reports\Types\WorkforceOverviewReport;
use InvalidArgumentException;

final class ReportRegistry
{
    private const TYPES = [
        SalesPerformanceReport::class,
        RevenueAnalysisReport::class,
        ExpenseAnalysisReport::class,
        BranchComparisonReport::class,
        ProductPerformanceReport::class,
        InventorySummaryReport::class,
        WorkforceOverviewReport::class,
    ];

    /** @return list<ReportType> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::TYPES);
    }

    public function get(string $key): ReportType
    {
        foreach ($this->all() as $type) {
            if ($type->key() === $key) {
                return $type;
            }
        }

        throw new InvalidArgumentException("Unknown report type [{$key}].");
    }

    public function has(string $key): bool
    {
        return in_array($key, array_map(fn (ReportType $t) => $t->key(), $this->all()), true);
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return array_map(fn (ReportType $t) => [
            'key' => $t->key(),
            'label' => $t->label(),
            'description' => $t->description(),
            'columns' => array_map(fn (ReportColumn $c) => $c->toArray(), $t->columns()),
            'default_columns' => $t->defaultColumns(),
            'default_sort' => $t->defaultSort(),
            'default_range' => $t->defaultRange(),
            'filters' => $t->filters(),
            'note' => $t->note(),
        ], $this->all());
    }
}
