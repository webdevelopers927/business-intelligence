<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Enums\DatasetType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

final class ProductsDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Products;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('sku', 'SKU', required: true, aliases: ['product code', 'item code', 'product id', 'item id', 'barcode'], maxLength: 64),
            new FieldDefinition('name', 'Product name', required: true, aliases: ['product', 'item', 'item name', 'description'], maxLength: 200),
            new FieldDefinition('category', 'Category', required: true, aliases: ['product category', 'category name', 'group'], maxLength: 120, hint: 'New categories are created automatically.'),
            new FieldDefinition('unit_price', 'Selling price', FieldDefinition::DECIMAL, true, ['price', 'unit price', 'retail price', 'sale price'], min: 0),
            new FieldDefinition('unit_cost', 'Unit cost', FieldDefinition::DECIMAL, true, ['cost', 'cost price', 'purchase price'], min: 0),
        ];
    }

    public function upserts(): bool
    {
        return true;
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow
    {
        return new PreparedRow(0, self::hash($values['sku']), $values);
    }

    public function existingKeys(array $rows): array
    {
        return [];
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        DB::table('products')->upsert(
            array_map(fn (PreparedRow $r) => [
                ...$this->lineage($import),
                'product_category_id' => $refs->ensureCategory('product_categories', $r->data['category']),
                'sku' => $r->data['sku'],
                'name' => $r->data['name'],
                'unit_price' => $r->data['unit_price'],
                'unit_cost' => $r->data['unit_cost'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['organization_id', 'sku'],
            ['product_category_id', 'name', 'unit_price', 'unit_cost', 'import_id', 'data_source_id', 'updated_at'],
        );
        $refs->forget('products');
    }
}
