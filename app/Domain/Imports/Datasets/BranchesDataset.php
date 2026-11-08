<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Enums\DatasetType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

final class BranchesDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Branches;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('code', 'Branch code', required: true, aliases: ['branch code', 'branch id', 'store code', 'location code'], maxLength: 32, hint: 'Short unique code, e.g. LHR-01.'),
            new FieldDefinition('name', 'Branch name', required: true, aliases: ['branch', 'store', 'location', 'branch name'], maxLength: 120),
            new FieldDefinition('city', 'City', aliases: ['town'], maxLength: 80),
            new FieldDefinition('opened_on', 'Opened on', FieldDefinition::DATE, aliases: ['opening date', 'opened']),
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
        DB::table('branches')->upsert(
            array_map(fn (PreparedRow $r) => [
                ...$this->lineage($import),
                'code' => $r->data['code'],
                'name' => $r->data['name'],
                'city' => $r->data['city'],
                'opened_on' => $r->data['opened_on']?->format('Y-m-d'),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['organization_id', 'code'],
            ['name', 'city', 'opened_on', 'import_id', 'data_source_id', 'updated_at'],
        );
        $refs->forget('branches');
    }
}
