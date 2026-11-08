<?php

namespace App\Domain\Imports;

use App\Domain\Imports\Datasets\BranchesDataset;
use App\Domain\Imports\Datasets\DatasetDefinition;
use App\Domain\Imports\Datasets\DepartmentsDataset;
use App\Domain\Imports\Datasets\EmployeesDataset;
use App\Domain\Imports\Datasets\ExpensesDataset;
use App\Domain\Imports\Datasets\InventoryDataset;
use App\Domain\Imports\Datasets\ProductsDataset;
use App\Domain\Imports\Datasets\SalesDataset;
use App\Enums\DatasetType;

final class DatasetRegistry
{
    public function for(DatasetType $type): DatasetDefinition
    {
        return app(match ($type) {
            DatasetType::Sales => SalesDataset::class,
            DatasetType::Expenses => ExpensesDataset::class,
            DatasetType::Products => ProductsDataset::class,
            DatasetType::Inventory => InventoryDataset::class,
            DatasetType::Employees => EmployeesDataset::class,
            DatasetType::Branches => BranchesDataset::class,
            DatasetType::Departments => DepartmentsDataset::class,
        });
    }
}
