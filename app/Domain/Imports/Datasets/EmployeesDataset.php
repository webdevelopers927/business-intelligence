<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Domain\Imports\RowError;
use App\Enums\DatasetType;
use App\Enums\EmploymentType;
use App\Models\Import;
use Illuminate\Support\Facades\DB;

/**
 * Headcount records, upserted by employee code so a monthly HR export can be
 * re-imported to update titles, departments and terminations.
 */
final class EmployeesDataset extends Dataset
{
    public function type(): DatasetType
    {
        return DatasetType::Employees;
    }

    public function fields(): array
    {
        return [
            new FieldDefinition('employee_code', 'Employee code', required: true, aliases: ['employee id', 'emp id', 'emp code', 'staff id', 'id'], maxLength: 32),
            new FieldDefinition('full_name', 'Full name', required: true, aliases: ['name', 'employee name', 'employee'], maxLength: 120),
            new FieldDefinition('department', 'Department', required: true, aliases: ['dept', 'department code'], maxLength: 120, hint: 'Department code or name.'),
            new FieldDefinition('branch', 'Branch', aliases: ['branch code', 'location', 'store'], maxLength: 120),
            new FieldDefinition('job_title', 'Job title', required: true, aliases: ['title', 'designation', 'position', 'role'], maxLength: 120),
            new FieldDefinition('employment_type', 'Employment type', FieldDefinition::ENUM, aliases: ['type', 'contract type'], options: array_column(EmploymentType::cases(), 'value')),
            new FieldDefinition('hired_on', 'Hire date', FieldDefinition::DATE, true, ['hire date', 'joining date', 'date of joining', 'start date', 'doj']),
            new FieldDefinition('terminated_on', 'Termination date', FieldDefinition::DATE, aliases: ['exit date', 'leaving date', 'end date']),
            new FieldDefinition('monthly_salary', 'Monthly salary', FieldDefinition::DECIMAL, aliases: ['salary', 'gross salary', 'monthly pay'], min: 0),
        ];
    }

    public function upserts(): bool
    {
        return true;
    }

    public function prepare(array $values, ReferenceResolver $refs): PreparedRow|RowError
    {
        $departmentId = $refs->department($values['department']);
        if ($departmentId === null) {
            return self::unknown('department', 'Department', $values['department']);
        }

        $branchId = null;
        if ($values['branch'] !== null && ($branchId = $refs->branch($values['branch'])) === null) {
            return self::unknown('branch', 'Branch', $values['branch']);
        }

        if ($values['terminated_on'] !== null && $values['terminated_on']->lt($values['hired_on'])) {
            return new RowError(RowError::OUT_OF_RANGE, 'Termination date is before the hire date.', 'terminated_on');
        }

        return new PreparedRow(0, self::hash($values['employee_code']), [
            'employee_code' => $values['employee_code'],
            'full_name' => $values['full_name'],
            'department_id' => $departmentId,
            'branch_id' => $branchId,
            'job_title' => $values['job_title'],
            'employment_type' => $values['employment_type'] ?? EmploymentType::FullTime->value,
            'hired_on' => $values['hired_on']->format('Y-m-d'),
            'terminated_on' => $values['terminated_on']?->format('Y-m-d'),
            'monthly_salary' => $values['monthly_salary'],
        ]);
    }

    public function existingKeys(array $rows): array
    {
        return [];
    }

    public function persist(array $rows, Import $import, ReferenceResolver $refs): void
    {
        $now = now();
        DB::table('employees')->upsert(
            array_map(fn (PreparedRow $r) => [
                ...$this->lineage($import),
                ...$r->data,
                'created_at' => $now,
                'updated_at' => $now,
            ], $rows),
            ['organization_id', 'employee_code'],
            ['full_name', 'department_id', 'branch_id', 'job_title', 'employment_type', 'hired_on', 'terminated_on', 'monthly_salary', 'import_id', 'data_source_id', 'updated_at'],
        );
    }
}
