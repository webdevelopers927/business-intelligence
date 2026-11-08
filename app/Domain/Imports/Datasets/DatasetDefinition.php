<?php

namespace App\Domain\Imports\Datasets;

use App\Domain\Imports\PreparedRow;
use App\Domain\Imports\ReferenceResolver;
use App\Domain\Imports\RowError;
use App\Enums\DatasetType;
use App\Models\Import;

/**
 * A controlled, importable dataset (docs/ARCHITECTURE.md §6.1).
 *
 * Lifecycle per chunk: RowValidator coerces mapped values → prepare()
 * resolves references and the natural key → duplicates are filtered →
 * persist() writes the chunk inside a transaction. finalize() runs once.
 */
interface DatasetDefinition
{
    public function type(): DatasetType;

    /** @return list<FieldDefinition> */
    public function fields(): array;

    /**
     * Upsert datasets (dimensions such as products) update an existing record
     * with the same natural key; fact datasets report it as a duplicate.
     */
    public function upserts(): bool;

    /** @param array<string, mixed> $values Coerced field values keyed by field key. */
    public function prepare(array $values, ReferenceResolver $refs): PreparedRow|RowError;

    /**
     * Natural keys of the given rows that already exist in the database.
     *
     * @param  list<PreparedRow>  $rows
     * @return list<string>
     */
    public function existingKeys(array $rows): array;

    /** @param list<PreparedRow> $rows */
    public function persist(array $rows, Import $import, ReferenceResolver $refs): void;

    /** Called once after every chunk has been persisted. */
    public function finalize(Import $import): void;
}
