<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Domain\Imports\RowError;
use App\Enums\DatasetType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Stock snapshots. Natural key: product + branch + date (one snapshot per
 * product per branch per day).
 */
final class InventoryDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Inventory;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('recorded_on', 'Snapshot date', FieldDefinition::DATE, true, ['date', 'stock date', 'as of', 'as of date', 'count date']),
            new FieldDefinition('sku', 'Product SKU', required: true, aliases: ['sku', 'product code', 'item code', 'barcode'], maxLength: 64),
            new FieldDefinition('branch', 'Branch', required: true, aliases: ['branch code', 'store', 'location', 'warehouse'], maxLength: 120),
            new FieldDefinition('quantity_on_hand', 'Quantity on hand', FieldDefinition::DECIMAL, true, ['qty', 'stock', 'on hand', 'quantity', 'soh', 'closing stock'], min: 0),
            new FieldDefinition('reorder_level', 'Reorder level', FieldDefinition::DECIMAL, aliases: ['reorder point', 'min stock', 'minimum'], min: 0),
            new FieldDefinition('unit_cost', 'Unit cost', FieldDefinition::DECIMAL, aliases: ['cost', 'cost price'], min: 0, hint: 'Defaults to the product\'s current unit cost.'),
        ];
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow|RowError
    {
        $product = $refs->product($values['sku']);
        if ($product === null) {
            return self::unknown('sku', 'Product SKU', $values['sku']);
        }

        $branchId = $refs->branch($values['branch']);
        if ($branchId === null) {
            return self::unknown('branch', 'Branch', $values['branch']);
        }

        $date = $values['recorded_on']->format('Y-m-d');

        return new PreparedRow(0, self::hash((string) $product['id'], (string) $branchId, $date), [
            'product_id' => $product['id'],
            'branch_id' => $branchId,
            'recorded_on' => $date,
            'quantity_on_hand' => $values['quantity_on_hand'],
            'reorder_level' => $values['reorder_level'] ?? 0,
            'unit_cost' => $values['unit_cost'] ?? $product['unit_cost'],
        ]);
    }

    public function existingKeys(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $dates = array_values(array_unique(array_map(fn (PreparedRow $r) => $r->data['recorded_on'], $rows)));
        $existing = DB::table('inventory_records')
            ->where('organization_id', $this->orgId())
            ->whereIn('recorded_on', $dates)
            ->whereIn('product_id', array_values(array_unique(array_map(fn (PreparedRow $r) => $r->data['product_id'], $rows))))
            ->get(['product_id', 'branch_id', 'recorded_on'])
            ->map(fn ($r) => self::hash((string) $r->product_id, (string) $r->branch_id, substr((string) $r->recorded_on, 0, 10)))
            ->all();

        return array_values(array_intersect(array_map(fn (PreparedRow $r) => $r->naturalKey, $rows), $existing));
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        $records = array_map(fn (PreparedRow $r) => [
            ...$this->lineage($import),
            ...$r->data,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        foreach (array_chunk($records, 250) as $batch) {
            DB::table('inventory_records')->insert($batch);
        }
    }
}
