<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Domain\Imports\RowError;
use App\Enums\DatasetType;
use App\Enums\SalesChannel;
use App\Enums\SalesStatus;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Line-level sales. Rows sharing a reference form one transaction; the
 * header is created from the first line seen, and header totals are
 * recomputed from items in finalize() so orders split across chunks (or
 * across imports) stay correct.
 *
 * Natural key: reference + SKU. A file that repeats a SKU within one order
 * should combine the quantities into one line.
 */
final class SalesDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Sales;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('reference', 'Transaction reference', required: true, aliases: ['order id', 'order no', 'order number', 'invoice', 'invoice no', 'receipt', 'transaction id', 'ref'], maxLength: 64),
            new FieldDefinition('occurred_at', 'Date', FieldDefinition::DATETIME, true, ['date', 'order date', 'transaction date', 'sale date', 'invoice date', 'datetime'], hint: 'Date, optionally with time, in your organization\'s timezone.'),
            new FieldDefinition('branch', 'Branch', required: true, aliases: ['branch code', 'store', 'location', 'outlet'], maxLength: 120, hint: 'Branch code or name.'),
            new FieldDefinition('sku', 'Product SKU', required: true, aliases: ['sku', 'product code', 'item code', 'product id', 'barcode'], maxLength: 64),
            new FieldDefinition('quantity', 'Quantity', FieldDefinition::DECIMAL, true, ['qty', 'units', 'quantity sold'], min: 0.001),
            new FieldDefinition('unit_price', 'Unit price', FieldDefinition::DECIMAL, true, ['price', 'rate', 'selling price'], min: 0),
            new FieldDefinition('discount', 'Discount', FieldDefinition::DECIMAL, aliases: ['discount amount', 'disc'], min: 0, hint: 'Line discount amount, not a percentage.'),
            new FieldDefinition('unit_cost', 'Unit cost', FieldDefinition::DECIMAL, aliases: ['cost', 'cost price'], min: 0, hint: 'Defaults to the product\'s current unit cost.'),
            new FieldDefinition('status', 'Status', FieldDefinition::ENUM, aliases: ['order status', 'state'], options: array_column(SalesStatus::cases(), 'value'), hint: 'Defaults to completed. Only completed sales count as revenue.'),
            new FieldDefinition('channel', 'Channel', FieldDefinition::ENUM, aliases: ['sales channel', 'source'], options: array_column(SalesChannel::cases(), 'value')),
            new FieldDefinition('customer_segment', 'Customer segment', aliases: ['segment', 'customer type'], maxLength: 32),
        ];
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow|RowError
    {
        $branchId = $refs->branch($values['branch']);
        if ($branchId === null) {
            return self::unknown('branch', 'Branch', $values['branch']);
        }

        $product = $refs->product($values['sku']);
        if ($product === null) {
            return self::unknown('sku', 'Product SKU', $values['sku']);
        }

        $discount = $values['discount'] ?? 0.0;
        $gross = round($values['quantity'] * $values['unit_price'], 2);
        $net = round($gross - $discount, 2);
        if ($net < 0) {
            return new RowError(RowError::OUT_OF_RANGE, 'Discount is larger than the line amount.', 'discount');
        }

        [$occurredAt, $businessDate] = $this->localize($values['occurred_at']);

        return new PreparedRow(0, self::hash($values['reference'], $values['sku']), [
            'reference' => $values['reference'],
            'branch_id' => $branchId,
            'product_id' => $product['id'],
            'occurred_at' => $occurredAt,
            'business_date' => $businessDate,
            'quantity' => $values['quantity'],
            'unit_price' => $values['unit_price'],
            'unit_cost' => $values['unit_cost'] ?? $product['unit_cost'],
            'discount_amount' => $discount,
            'net_amount' => $net,
            'status' => $values['status'] ?? SalesStatus::Completed->value,
            'channel' => $values['channel'] ?? SalesChannel::Store->value,
            'customer_segment' => $values['customer_segment'],
        ]);
    }

    public function existingKeys(array $rows): array
    {
        return $this->existingHashes('sales_transaction_items', $rows);
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        $references = array_values(array_unique(array_map(fn (PreparedRow $r) => $r->data['reference'], $rows)));
        $headerIds = $this->headerIds($references);

        $newHeaders = [];
        foreach ($rows as $row) {
            $ref = $row->data['reference'];
            if (! isset($headerIds[$ref]) && ! isset($newHeaders[$ref])) {
                $newHeaders[$ref] = [
                    ...$this->lineage($import),
                    'branch_id' => $row->data['branch_id'],
                    'reference' => $ref,
                    'occurred_at' => $row->data['occurred_at'],
                    'business_date' => $row->data['business_date'],
                    'status' => $row->data['status'],
                    'channel' => $row->data['channel'],
                    'customer_segment' => $row->data['customer_segment'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($newHeaders !== []) {
            DB::table('sales_transactions')->insert(array_values($newHeaders));
            $headerIds = $this->headerIds($references);
        }

        $items = array_map(function (PreparedRow $r) use ($import, $headerIds) {
            return [
                ...$this->lineage($import),
                'sales_transaction_id' => $headerIds[$r->data['reference']],
                'product_id' => $r->data['product_id'],
                'branch_id' => $r->data['branch_id'],
                'business_date' => $r->data['business_date'],
                'quantity' => $r->data['quantity'],
                'unit_price' => $r->data['unit_price'],
                'unit_cost' => $r->data['unit_cost'],
                'discount_amount' => $r->data['discount_amount'],
                'net_amount' => $r->data['net_amount'],
                'natural_key_hash' => $r->naturalKey,
            ];
        }, $rows);

        foreach (array_chunk($items, 250) as $batch) {
            DB::table('sales_transaction_items')->insert($batch);
        }
    }

    /**
     * Header totals = sum of their items, for every header that received
     * items in this import. Portable correlated-subquery UPDATE (SQLite/MySQL).
     */
    public function finalize(Import $import): void
    {
        $sum = fn (string $expr) => DB::raw("(SELECT COALESCE({$expr}, 0) FROM sales_transaction_items i WHERE i.sales_transaction_id = sales_transactions.id)");

        DB::table('sales_transactions')
            ->where('organization_id', $this->orgId())
            ->whereIn('id', DB::table('sales_transaction_items')->select('sales_transaction_id')->where('import_id', $import->id))
            ->update([
                'items_count' => $sum('COUNT(*)'),
                'gross_amount' => $sum('SUM(i.net_amount + i.discount_amount)'),
                'discount_amount' => $sum('SUM(i.discount_amount)'),
                'net_amount' => $sum('SUM(i.net_amount)'),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $references
     * @return array<string, int>
     */
    private function headerIds(array $references): array
    {
        return DB::table('sales_transactions')
            ->where('organization_id', $this->orgId())
            ->whereIn('reference', $references)
            ->pluck('id', 'reference')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
