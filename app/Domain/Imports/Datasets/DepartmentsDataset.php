<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Enums\DatasetType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

final class DepartmentsDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Departments;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('code', 'Department code', required: true, aliases: ['dept code', 'department id', 'dept id'], maxLength: 32),
            new FieldDefinition('name', 'Department name', required: true, aliases: ['department', 'dept', 'dept name'], maxLength: 120),
        ];
    }

    public function upserts(): bool
    {
        return true;
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow
    {
        return new PreparedRow(0, self::hash($values['code']), $values);
    }

    public function existingKeys(array $rows): array
    {
        return [];
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        DB::table('departments')->upsert(
            array_map(fn (PreparedRow $r) => [
                ...$this->lineage($import),
                'code' => $r->data['code'],
                'name' => $r->data['name'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['organization_id', 'code'],
            ['name', 'import_id', 'data_source_id', 'updated_at'],
        );
        $refs->forget('departments');
    }
}
