<?php

namespace App\Domain\Imports;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-import lookup maps from human identifiers (branch code or name,
 * product SKU, category name…) to ids, scoped to the current organization.
 *
 * Maps are loaded once per import and kept in sync as the import creates
 * records, so a reference resolves without a query per row.
 */
final class ReferenceResolver
{
    /** @var array<string, array<string, int>> */
    private array $maps = [];

    /** @var array<string, array{id: int, unit_cost: float}> */
    private ?array $products = null;

    public function __construct(private readonly TenantContext $tenant) {}

    public function branch(?string $codeOrName): ?int
    {
        return $this->lookup('branches', $codeOrName, ['code', 'name']);
    }

    public function department(?string $codeOrName): ?int
    {
        return $this->lookup('departments', $codeOrName, ['code', 'name']);
    }

    public function productCategory(?string $name): ?int
    {
        return $this->lookup('product_categories', $name, ['name']);
    }

    public function expenseCategory(?string $name): ?int
    {
        return $this->lookup('expense_categories', $name, ['name']);
    }

    /** @return array{id: int, unit_cost: float}|null */
    public function product(?string $sku): ?array
    {
        if ($sku === null) {
            return null;
        }

        if ($this->products === null) {
            $this->products = [];
            foreach (DB::table('products')->where('organization_id', $this->tenant->id())->get(['id', 'sku', 'unit_cost']) as $p) {
                $this->products[mb_strtolower($p->sku)] = ['id' => (int) $p->id, 'unit_cost' => (float) $p->unit_cost];
            }
        }

        return $this->products[mb_strtolower($sku)] ?? null;
    }

    /** Find or create a name-keyed category (product or expense) and return its id. */
    public function ensureCategory(string $table, string $name): int
    {
        $existing = $this->lookup($table, $name, ['name']);
        if ($existing !== null) {
            return $existing;
        }

        $now = now();
        $id = (int) DB::table($table)->insertGetId([
            'organization_id' => $this->tenant->id(),
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->maps[$table][mb_strtolower($name)] = $id;

        return $id;
    }

    /** Drop cached maps after a chunk upserts dimension rows. */
    public function forget(string $table): void
    {
        unset($this->maps[$table]);
        if ($table === 'products') {
            $this->products = null;
        }
    }

    /** @param list<string> $columns */
    private function lookup(string $table, ?string $value, array $columns): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! isset($this->maps[$table])) {
            $this->maps[$table] = [];
            $rows = DB::table($table)->where('organization_id', $this->tenant->id())->get(['id', ...$columns]);
            // Later columns first so that the primary column (code) wins on collisions.
            foreach (array_reverse($columns) as $column) {
                foreach ($rows as $row) {
                    $this->maps[$table][mb_strtolower((string) $row->{$column})] = (int) $row->id;
                }
            }
        }

        return $this->maps[$table][mb_strtolower($value)] ?? null;
    }
}
