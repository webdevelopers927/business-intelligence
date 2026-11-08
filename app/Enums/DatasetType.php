<?php

namespace App\Enums;

enum DatasetType: string
{
    case Sales = 'sales';
    case Expenses = 'expenses';
    case Products = 'products';
    case Inventory = 'inventory';
    case Employees = 'employees';
    case Branches = 'branches';
    case Departments = 'departments';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Sales transactions',
            self::Expenses => 'Expenses',
            self::Products => 'Products',
            self::Inventory => 'Inventory snapshots',
            self::Employees => 'Employees',
            self::Branches => 'Branches',
            self::Departments => 'Departments',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sales => 'One row per order line: reference, date, branch, product SKU, quantity and price.',
            self::Expenses => 'Operating costs with date, category, amount and optional branch or department.',
            self::Products => 'Catalog with SKU, name, category, selling price and unit cost.',
            self::Inventory => 'Stock on hand per product and branch on a given date.',
            self::Employees => 'Headcount records with department, branch, role and hire date.',
            self::Branches => 'Locations with a short code and city.',
            self::Departments => 'Organizational units with a short code.',
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t) => [
            'value' => $t->value,
            'label' => $t->label(),
            'description' => $t->description(),
        ], self::cases());
    }
}
