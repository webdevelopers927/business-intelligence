<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Domain\Imports\RowError;
use App\Enums\DatasetType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Natural key: the expense reference when present (voucher/bill number);
 * otherwise a hash of every descriptive field. Two identical unreferenced
 * expenses on the same day are therefore treated as a duplicate — files
 * that legitimately contain them should include a reference column.
 */
final class ExpensesDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Expenses;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('business_date', 'Date', FieldDefinition::DATE, true, ['date', 'expense date', 'bill date', 'posting date']),
            new FieldDefinition('category', 'Category', required: true, aliases: ['expense category', 'expense type', 'type', 'account', 'head'], maxLength: 120, hint: 'New categories are created automatically.'),
            new FieldDefinition('amount', 'Amount', FieldDefinition::DECIMAL, true, ['total', 'value', 'expense amount', 'debit'], min: 0),
            new FieldDefinition('branch', 'Branch', aliases: ['branch code', 'store', 'location', 'cost center'], maxLength: 120, hint: 'Leave empty for organization-wide costs.'),
            new FieldDefinition('department', 'Department', aliases: ['dept', 'department code'], maxLength: 120),
            new FieldDefinition('vendor', 'Vendor', aliases: ['supplier', 'payee'], maxLength: 120),
            new FieldDefinition('reference', 'Reference', aliases: ['voucher', 'voucher no', 'bill no', 'invoice no', 'expense id'], maxLength: 64),
            new FieldDefinition('description', 'Description', aliases: ['memo', 'narration', 'notes', 'details']),
        ];
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow|RowError
    {
        $branchId = null;
        if ($values['branch'] !== null && ($branchId = $refs->branch($values['branch'])) === null) {
            return self::unknown('branch', 'Branch', $values['branch']);
        }

        $departmentId = null;
        if ($values['department'] !== null && ($departmentId = $refs->department($values['department'])) === null) {
            return self::unknown('department', 'Department', $values['department']);
        }

        $date = $values['business_date']->format('Y-m-d');
        $key = $values['reference'] !== null
            ? self::hash('ref', $values['reference'])
            : self::hash($date, $values['category'], number_format($values['amount'], 2, '.', ''), (string) $values['vendor'], (string) $branchId, (string) $departmentId, (string) $values['description']);

        return new PreparedRow(0, $key, [
            'business_date' => $date,
            'category' => $values['category'],
            'amount' => $values['amount'],
            'branch_id' => $branchId,
            'department_id' => $departmentId,
            'vendor' => $values['vendor'],
            'reference' => $values['reference'],
            'description' => $values['description'],
        ]);
    }

    public function existingKeys(array $rows): array
    {
        return $this->existingHashes('expenses', $rows);
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        $records = array_map(fn (PreparedRow $r) => [
            ...$this->lineage($import),
            'expense_category_id' => $refs->ensureCategory('expense_categories', $r->data['category']),
            'branch_id' => $r->data['branch_id'],
            'department_id' => $r->data['department_id'],
            'business_date' => $r->data['business_date'],
            'amount' => $r->data['amount'],
            'reference' => $r->data['reference'],
            'vendor' => $r->data['vendor'],
            'description' => $r->data['description'],
            'natural_key_hash' => $r->naturalKey,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        foreach (array_chunk($records, 250) as $batch) {
            DB::table('expenses')->insert($batch);
        }
    }
}
