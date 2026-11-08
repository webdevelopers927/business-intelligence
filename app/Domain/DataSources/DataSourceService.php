<?php

namespace App\Domain\DataSources;

use App\Domain\Audit\AuditLogger;
use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DataSourceService
{
    /** Config keys allowed per type; everything secret goes to credentials. */
    private const CONFIG_KEYS = [
        'mysql' => ['host', 'port', 'database', 'username', 'table'],
        'postgres' => ['host', 'port', 'database', 'username', 'table'],
        'rest' => ['url', 'auth_header', 'records_path'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionTester $tester,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string|null>  $secret  Empty values keep the stored secret.
     */
    public function create(User $creator, string $name, DataSourceType $type, ?string $description, array $config, array $secret): DataSource
    {
        return DB::transaction(function () use ($creator, $name, $type, $description, $config, $secret) {
            $source = DataSource::query()->create([
                'name' => $name,
                'type' => $type,
                'description' => $description,
                'config' => $this->filterConfig($type, $config),
                // File sources are usable immediately; connections must pass a test first.
                'status' => $type->isFile() ? DataSourceStatus::Active : DataSourceStatus::Pending,
                'created_by' => $creator->id,
            ]);

            $this->storeSecret($source, $secret);
            $this->audit->record('data_source.created', $source, ['type' => $type->value, 'name' => $name]);

            return $source;
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string|null>  $secret
     */
    public function update(DataSource $source, string $name, ?string $description, array $config, array $secret): DataSource
    {
        return DB::transaction(function () use ($source, $name, $description, $config, $secret) {
            $newConfig = $this->filterConfig($source->type, $config);
            $connectionChanged = $newConfig !== ($source->config ?? []) || array_filter($secret) !== [];

            $source->fill(['name' => $name, 'description' => $description, 'config' => $newConfig]);
            $changed = array_keys($source->getDirty());

            if ($connectionChanged && ! $source->type->isFile() && $source->status !== DataSourceStatus::Disabled) {
                // A changed connection must be re-tested before it's trusted.
                $source->status = DataSourceStatus::Pending;
            }

            $source->save();
            $rotated = $this->storeSecret($source, $secret);

            $this->audit->record('data_source.updated', $source, [
                'changed' => $changed,
                'credentials_rotated' => $rotated,
            ]);

            return $source;
        });
    }

    /** @return array{ok: bool, message: string} */
    public function test(DataSource $source): array
    {
        $secret = $source->credential->secret ?? [];

        try {
            $message = $this->tester->test($source, $secret);
            $source->forceFill([
                'status' => $source->status === DataSourceStatus::Disabled ? DataSourceStatus::Disabled : DataSourceStatus::Active,
                'last_tested_at' => now(),
                'last_error' => null,
            ])->save();
            $ok = true;
        } catch (ConnectionRefused $e) {
            $message = $e->getMessage();
            $source->forceFill([
                'status' => $source->status === DataSourceStatus::Disabled ? DataSourceStatus::Disabled : DataSourceStatus::Error,
                'last_tested_at' => now(),
                'last_error' => $message,
            ])->save();
            $ok = false;
        }

        $this->audit->record('data_source.tested', $source, ['ok' => $ok]);

        return ['ok' => $ok, 'message' => $message];
    }

    public function toggle(DataSource $source): DataSource
    {
        $enable = $source->status === DataSourceStatus::Disabled;
        $source->status = $enable
            ? ($source->type->isFile() ? DataSourceStatus::Active : DataSourceStatus::Pending)
            : DataSourceStatus::Disabled;
        $source->save();

        $this->audit->record($enable ? 'data_source.enabled' : 'data_source.disabled', $source);

        return $source;
    }

    public function delete(DataSource $source): void
    {
        DB::transaction(function () use ($source) {
            $source->credential()->delete();
            $source->delete();
            $this->audit->record('data_source.deleted', $source, ['name' => $source->name]);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function filterConfig(DataSourceType $type, array $config): array
    {
        $allowed = self::CONFIG_KEYS[$type->value] ?? [];
        $filtered = array_intersect_key($config, array_flip($allowed));

        if (isset($filtered['port'])) {
            $filtered['port'] = (int) $filtered['port'];
        }

        return array_filter($filtered, fn ($v) => $v !== null && $v !== '');
    }

    /** @param array<string, string|null> $secret */
    private function storeSecret(DataSource $source, array $secret): bool
    {
        $provided = array_filter($secret, fn ($v) => $v !== null && $v !== '');
        if ($provided === []) {
            return false;
        }

        $existing = $source->credential->secret ?? [];
        $source->credential()->updateOrCreate([], ['secret' => [...$existing, ...$provided], 'rotated_at' => now()]);
        $source->unsetRelation('credential');

        return true;
    }
}
