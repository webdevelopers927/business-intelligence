<?php

namespace App\Domain\Reports\Types;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Transaction-level listing. Stays in the database: paginated for preview,
 * chunked for export, so a year of transactions never sits in memory.
 */
final class SalesPerformanceReport extends ReportType
{
    private const SORTABLE = [
        'reference' => 't.reference', 'occurred_at' => 't.occurred_at', 'branch' => 'b.name', 'channel' => 't.channel',
        'status' => 't.status', 'items' => 't.items_count', 'gross' => 't.gross_amount', 'discount' => 't.discount_amount', 'net' => 't.net_amount',
    ];

    public function key(): string
    {
        return 'sales_performance';
    }

    public function label(): string
    {
        return 'Sales performance';
    }

    public function description(): string
    {
        return 'Every transaction in the period with branch, channel, status and amounts.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('reference', 'Reference'),
            new ReportColumn('occurred_at', 'Date', 'datetime'),
            new ReportColumn('branch', 'Branch'),
            new ReportColumn('channel', 'Channel'),
            new ReportColumn('status', 'Status'),
            new ReportColumn('items', 'Items', 'number'),
            new ReportColumn('gross', 'Gross', 'currency'),
            new ReportColumn('discount', 'Discount', 'currency'),
            new ReportColumn('net', 'Net amount', 'currency'),
        ];
    }

    public function defaultSort(): array
    {
        return ['occurred_at', 'desc'];
    }

    public function note(): string
    {
        return 'Includes all statuses. Only completed transactions count toward revenue elsewhere in ORBIT.';
    }

    public function count(ReportParameters $p): int
    {
        return $this->query($p)->count();
    }

    public function page(ReportParameters $p, int $page, int $perPage): array
    {
        return $this->query($p)->forPage($page, $perPage)->get()->map(fn ($r) => $this->map($r))->all();
    }

    public function cursor(ReportParameters $p): Generator
    {
        foreach ($this->query($p)->lazy(1000) as $row) {
            yield $this->map($row);
        }
    }

    protected function rows(ReportParameters $p): array
    {
        return $this->page($p, 1, PHP_INT_MAX);
    }

    private function query(ReportParameters $p): Builder
    {
        return DB::table('sales_transactions as t')
            ->join('branches as b', 'b.id', '=', 't.branch_id')
            ->where('t.organization_id', $this->tenant->id())
            ->whereBetween('t.business_date', [$p->range->from->toDateString(), $p->range->to->toDateString()])
            ->when($p->filters->branchIds !== [], fn (Builder $q) => $q->whereIn('t.branch_id', $p->filters->branchIds))
            ->when($p->filters->categoryIds !== [], fn (Builder $q) => $q->whereExists(fn (Builder $s) => $s->selectRaw('1')
                ->from('sales_transaction_items as i')
                ->join('products as pr', 'pr.id', '=', 'i.product_id')
                ->whereColumn('i.sales_transaction_id', 't.id')
                ->whereIn('pr.product_category_id', $p->filters->categoryIds)))
            ->orderBy(self::SORTABLE[$p->sort] ?? 't.occurred_at', $p->direction)
            ->orderBy('t.id', $p->direction)
            ->select(['t.reference', 't.occurred_at', 'b.name as branch', 't.channel', 't.status', 't.items_count', 't.gross_amount', 't.discount_amount', 't.net_amount']);
    }

    /** @return array<string, mixed> */
    private function map(object $r): array
    {
        return [
            'reference' => $r->reference,
            'occurred_at' => CarbonImmutable::parse($r->occurred_at, 'UTC')->setTimezone($this->tenant->organization()->timezone)->format('Y-m-d H:i'),
            'branch' => $r->branch,
            'channel' => ucfirst((string) $r->channel),
            'status' => ucfirst((string) $r->status),
            'items' => (int) $r->items_count,
            'gross' => (float) $r->gross_amount,
            'discount' => (float) $r->discount_amount,
            'net' => (float) $r->net_amount,
        ];
    }
}
