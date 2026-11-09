<?php

namespace App\Domain\Reports\Types;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;
use Illuminate\Support\Facades\DB;

/** Stock position per product and branch from the latest snapshot on or before the range end. */
final class InventorySummaryReport extends ReportType
{
    public function key(): string
    {
        return 'inventory_summary';
    }

    public function label(): string
    {
        return 'Inventory summary';
    }

    public function description(): string
    {
        return 'Stock on hand, value and reorder status per product and branch as of the end of the period.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('sku', 'SKU'),
            new ReportColumn('product', 'Product'),
            new ReportColumn('category', 'Category'),
            new ReportColumn('branch', 'Branch'),
            new ReportColumn('on_hand', 'On hand', 'number'),
            new ReportColumn('reorder_level', 'Reorder level', 'number'),
            new ReportColumn('unit_cost', 'Unit cost', 'currency'),
            new ReportColumn('stock_value', 'Stock value', 'currency'),
            new ReportColumn('status', 'Status'),
            new ReportColumn('as_of', 'Snapshot date', 'date'),
        ];
    }

    public function defaultSort(): array
    {
        return ['stock_value', 'desc'];
    }

    public function defaultRange(): string
    {
        return 'this_month';
    }

    public function note(): string
    {
        return 'A snapshot report: it uses the latest stock count on or before the end of the selected period.';
    }

    protected function rows(ReportParameters $p): array
    {
        $org = $this->tenant->id();
        $asOf = $p->range->to->toDateString();

        return DB::table('inventory_records as r')
            ->join('products as p', 'p.id', '=', 'r.product_id')
            ->join('product_categories as c', 'c.id', '=', 'p.product_category_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')
            ->where('r.organization_id', $org)
            ->whereRaw('r.recorded_on = (SELECT MAX(r2.recorded_on) FROM inventory_records r2 WHERE r2.organization_id = r.organization_id AND r2.product_id = r.product_id AND r2.branch_id = r.branch_id AND r2.recorded_on <= ?)', [$asOf])
            ->when($p->filters->branchIds !== [], fn ($q) => $q->whereIn('r.branch_id', $p->filters->branchIds))
            ->when($p->filters->categoryIds !== [], fn ($q) => $q->whereIn('p.product_category_id', $p->filters->categoryIds))
            ->get(['p.sku', 'p.name as product', 'c.name as category', 'b.name as branch', 'r.quantity_on_hand', 'r.reorder_level', 'r.unit_cost', 'r.recorded_on'])
            ->map(fn ($r) => [
                'sku' => $r->sku,
                'product' => $r->product,
                'category' => $r->category,
                'branch' => $r->branch,
                'on_hand' => (float) $r->quantity_on_hand,
                'reorder_level' => (float) $r->reorder_level,
                'unit_cost' => (float) $r->unit_cost,
                'stock_value' => round((float) $r->quantity_on_hand * (float) $r->unit_cost, 2),
                'status' => (float) $r->quantity_on_hand <= (float) $r->reorder_level ? 'Reorder' : 'OK',
                'as_of' => substr((string) $r->recorded_on, 0, 10),
            ])
            ->all();
    }
}
