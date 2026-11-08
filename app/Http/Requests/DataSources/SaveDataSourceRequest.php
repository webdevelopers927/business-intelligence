<?php

namespace App\Http\Requests\DataSources;

use App\Domain\Tenancy\TenantContext;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share rules. On update the type is fixed (taken from the
 * existing source) and secret fields may be left blank to keep the stored value.
 */
class SaveDataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $source = $this->route('source');

        return $source instanceof DataSource
            ? $this->user()?->can('update', $source) ?? false
            : $this->user()?->can('create', DataSource::class) ?? false;
    }

    public function type(): DataSourceType
    {
        $source = $this->route('source');

        return $source instanceof DataSource ? $source->type : DataSourceType::from((string) $this->input('type'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $source = $this->route('source');
        $isUpdate = $source instanceof DataSource;
        $hasSecret = $isUpdate && $source->credential !== null;
        $type = $isUpdate ? $source->type : DataSourceType::tryFrom((string) $this->input('type'));

        $rules = [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('data_sources', 'name')
                    ->where('organization_id', app(TenantContext::class)->id())
                    ->whereNull('deleted_at')
                    ->ignore($isUpdate ? $source->id : null),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ];

        if (! $isUpdate) {
            $rules['type'] = ['required', Rule::enum(DataSourceType::class)];
        }

        if ($type?->isDatabase()) {
            $rules += [
                'config.host' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
                'config.port' => ['required', 'integer', 'between:1,65535'],
                'config.database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-$]+$/'],
                'config.username' => ['required', 'string', 'max:64'],
                'config.table' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.$]+$/'],
                'secret.password' => [$hasSecret ? 'nullable' : 'required', 'string', 'max:255'],
            ];
        }

        if ($type === DataSourceType::Rest) {
            $rules += [
                'config.url' => ['required', 'url:http,https', 'max:2048'],
                'config.auth_header' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
                'config.records_path' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.\-]+$/'],
                'secret.token' => ['nullable', 'string', 'max:2048'],
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'config.host' => 'host',
            'config.port' => 'port',
            'config.database' => 'database',
            'config.username' => 'username',
            'config.table' => 'table or view',
            'config.url' => 'URL',
            'config.auth_header' => 'auth header',
            'config.records_path' => 'records path',
            'secret.password' => 'password',
            'secret.token' => 'API token',
        ];
    }
}
