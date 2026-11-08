<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\RowError;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shared helpers for dataset definitions. Persistence uses the query builder
 * (bulk inserts/upserts) with organization_id set explicitly from the tenant
 * context — the model-layer scope does not apply to DB::table().
 */
abstract class Dataset implements DatasetDefinition
{
    public function __construct(protected readonly TenantContext $tenant) {}

    public function upserts(): bool
    {
        return false;
    }

    public function finalize(Import $import): void {}

    protected function orgId(): int
    {
        return $this->tenant->id();
    }

    protected static function hash(string ...$parts): string
    {
        return sha1(mb_strtolower(implode("\x1f", $parts)));
    }

    protected static function unknown(string $field, string $label, string $value): RowError
    {
        return new RowError(RowError::UNKNOWN_REFERENCE, "{$label} \"{$value}\" was not found. Import it first or fix the value.", $field);
    }

    /**
     * Interpret a parsed wall-clock value in the organization's timezone:
     * returns [UTC timestamp string, local business date].
     *
     * @return array{0: string, 1: string}
     */
    protected function localize(CarbonImmutable $value): array
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value->format('Y-m-d H:i:s'), $this->tenant->organization()->timezone);

        return [$local->utc()->format('Y-m-d H:i:s'), $local->format('Y-m-d')];
    }

    /**
     * Natural keys of $rows already present in $table.natural_key_hash.
     *
     * @param  list<PreparedRow>  $rows
     * @return list<string>
     */
    protected function existingHashes(string $table, array $rows): array
    {
        $keys = array_map(fn (PreparedRow $r) => $r->naturalKey, $rows);
        if ($keys === []) {
            return [];
        }

        return DB::table($table)
            ->where('organization_id', $this->orgId())
            ->whereIn('natural_key_hash', $keys)
            ->pluck('natural_key_hash')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function lineage(Import $import, array $extra = []): array
    {
        return [
            'organization_id' => $this->orgId(),
            'import_id' => $import->id,
            'data_source_id' => $import->data_source_id,
            ...$extra,
        ];
    }
}
