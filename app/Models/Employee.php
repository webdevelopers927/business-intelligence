<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hired_on' => 'date',
            'terminated_on' => 'date',
            'employment_type' => EmploymentType::class,
            'monthly_salary' => 'decimal:2',
        ];
    }
}
