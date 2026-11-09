<?php

namespace App\Domain\Reports\Types;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;
use Illuminate\Support\Facades\DB;

/** Units, revenue, cost and margin for every product sold in the period. */
final class ProductPerformanceReport extends ReportType
{
    public function key(): string
    {
        return 'product_performance';
    }

    public function label(): string
    {
        return 'Product performance';
    }

    public function description(): string
    {
        return 'Units sold, revenue, gross profit, margin and revenue share for each product.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('sku', 'SKU'),
            new ReportColumn('product', 'Product'),
            new ReportColumn('category', 'Category'),
            new ReportColumn('units', 'Units sold', 'number'),
            new ReportColumn('revenue', 'Revenue', 'currency'),
            new ReportColumn('cogs', 'COGS', 'currency'),
            new ReportColumn('gross_profit', 'Gross profit', 'currency'),
            new ReportColumn('gross_margin', 'Gross margin', 'percent'),
            new ReportColumn('share', 'Revenue share', 'percent'),
        ];
    }

    public function defaultSort(): array
    {
        return ['revenue', 'desc'];
    }

    protected function rows(ReportParameters $p): array
    {
        $rows = $this->engine->breakdown($p->range, $p->filters, 'product', ['units_sold', 'revenue', 'cogs', 'gross_profit', 'gross_margin']);
        $total = array_sum(array_map(fn ($r) => $r['values']['revenue'] ?? 0, $rows));
        $meta = DB::table('products as p')
            ->join('product_categories as c', 'c.id', '=', 'p.product_category_id')
            ->where('p.organization_id', $this->tenant->id())
            ->get(['p.id', 'p.sku', 'c.name as category'])
            ->keyBy('id');

        return array_map(fn ($r) => [
            'sku' => $meta[$r['id']]->sku ?? '',
            'product' => $r['label'],
            'category' => $meta[$r['id']]->category ?? '',
            'units' => $r['values']['units_sold'],
            'revenue' => $r['values']['revenue'],
            'cogs' => $r['values']['cogs'],
            'gross_profit' => $r['values']['gross_profit'],
            'gross_margin' => $r['values']['gross_margin'],
            'share' => $total > 0 ? round(($r['values']['revenue'] ?? 0) / $total * 100, 2) : null,
        ], $rows);
    }
}
