<?php

namespace App\Http\Requests;

use App\Enums\Industry;
use App\Enums\OrganizationSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'industry' => ['required', Rule::enum(Industry::class)],
            'size' => ['required', Rule::enum(OrganizationSize::class)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'organization name', 'size' => 'business size'];
    }
}
