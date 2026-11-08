<?php

namespace App\Http\Requests\Settings;

use App\Enums\Industry;
use App\Enums\OrganizationSize;
use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::OrgSettingsManage->value) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'industry' => ['required', Rule::enum(Industry::class)],
            'size' => ['required', Rule::enum(OrganizationSize::class)],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', 'alpha', 'uppercase'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
        ];
    }
}
