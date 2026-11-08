<?php

namespace App\Domain\Imports;

use App\Domain\Analytics\AnalyticsCache;
use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Enums\ImportStatus;
use App\Models\Import;
use App\Notifications\ImportFinished;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs an import inside the job: streams rows, validates, de-duplicates and
 * persists in chunks. Each chunk (rows + their errors + counters) commits in
 * one transaction, so a retried job resumes after the last committed row.
 */
final class ImportProcessor
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly ImportService $imports,
        private readonly AuditLogger $audit,
        private readonly AnalyticsCache $cache,
        private readonly TenantContext $tenant,
    ) {}

    public function process(Import $import): void
    {
        $dataset = $this->datasets->for($import->dataset_type);
        $refs = new ReferenceResolver($this->tenant);
        $validator = new RowValidator($dataset->fields(), $import->mappingArray(), $import->options['date_format'] ?? 'Y-m-d');
        $reader = $this->imports->reader($import);

        if ($import->status === ImportStatus::Pending) {
            $import->update([
                'status' => ImportStatus::Processing,
                'started_at' => now(),
                'total_rows' => $reader->countRows(),
            ]);
        }

        $resumeAfter = $import->processed_rows;
        $seenKeys = [];
        $position = 0;
        $chunk = [];

        foreach ($reader->rows() as $rowNumber => $raw) {
            $position++;
            if ($position <= $resumeAfter) {
                // Already committed by a previous attempt; still remember keys
                // would require re-validation, so in-file duplicates spanning a
                // resume boundary are caught by the database check instead.
                continue;
            }

            $chunk[$rowNumber] = $raw;
            if (count($chunk) >= self::CHUNK_SIZE) {
                $this->processChunk($import, $chunk, $dataset, $validator, $refs, $seenKeys);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->processChunk($import, $chunk, $dataset, $validator, $refs, $seenKeys);
        }

        DB::transaction(fn () => $dataset->finalize($import));

        $status = match (true) {
            $import->imported_rows === 0 => ImportStatus::Failed,
            $import->failed_rows > 0 => ImportStatus::CompletedWithErrors,
            default => ImportStatus::Completed,
        };

        $import->update([
            'status' => $status,
            'completed_at' => now(),
            'failure_reason' => $status === ImportStatus::Failed ? 'No rows could be imported. Download the failed rows to see why.' : null,
        ]);

        $this->finish($import);
    }

    public function fail(Import $import, Throwable $e): void
    {
        $import->update([
            'status' => ImportStatus::Failed,
            'completed_at' => now(),
            'failure_reason' => 'Processing stopped unexpectedly: '.mb_substr($e->getMessage(), 0, 300),
        ]);

        $this->finish($import);
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk  row number => raw values
     * @param  array<string, true>  $seenKeys
     */
    private function processChunk(Import $import, array $chunk, Datasets\DatasetDefinition $dataset, RowValidator $validator, ReferenceResolver $refs, array &$seenKeys): void
    {
        /** @var list<PreparedRow> $prepared */
        $prepared = [];
        /** @var list<array{0: int, 1: RowError, 2: array<string, mixed>}> $rejected */
        $rejected = [];

        foreach ($chunk as $rowNumber => $raw) {
            [$values, $errors] = $validator->validate($raw);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    $rejected[] = [$rowNumber, $error, $raw];
                }

                continue;
            }

            $result = $dataset->prepare($values, $refs);
            if ($result instanceof RowError) {
                $rejected[] = [$rowNumber, $result, $raw];

                continue;
            }

            if (isset($seenKeys[$result->naturalKey])) {
                $rejected[] = [$rowNumber, new RowError(RowError::DUPLICATE_IN_FILE, 'This row repeats an earlier row in the same file.'), $raw];

                continue;
            }

            $seenKeys[$result->naturalKey] = true;
            $prepared[] = $result->withRowNumber($rowNumber);
        }

        if (! $dataset->upserts()) {
            $existing = array_flip($dataset->existingKeys($prepared));
            $prepared = array_values(array_filter($prepared, function (PreparedRow $row) use ($existing, $chunk, &$rejected) {
                if (isset($existing[$row->naturalKey])) {
                    $rejected[] = [$row->rowNumber, new RowError(RowError::DUPLICATE, 'This record was already imported earlier.'), $chunk[$row->rowNumber]];

                    return false;
                }

                return true;
            }));
        }

        $failedRows = count(array_unique(array_map(fn ($r) => $r[0], $rejected)));
        $duplicateRows = count(array_unique(array_map(fn ($r) => $r[0], array_filter($rejected, fn ($r) => $r[1]->isDuplicate()))));

        DB::transaction(function () use ($import, $dataset, $refs, $prepared, $rejected, $chunk, $failedRows, $duplicateRows) {
            if ($prepared !== []) {
                $dataset->persist($prepared, $import, $refs);
            }

            $now = now();
            foreach (array_chunk($rejected, 200) as $batch) {
                DB::table('import_errors')->insert(array_map(fn ($r) => [
                    'import_id' => $import->id,
                    'row_number' => $r[0],
                    'field' => $r[1]->field,
                    'code' => $r[1]->code,
                    'message' => mb_substr($r[1]->message, 0, 500),
                    'raw' => json_encode(array_map(fn ($v) => ValueParser::string($v), $r[2])),
                    'created_at' => $now,
                ], $batch));
            }

            $import->update([
                'processed_rows' => $import->processed_rows + count($chunk),
                'imported_rows' => $import->imported_rows + count($prepared),
                'failed_rows' => $import->failed_rows + $failedRows,
                'duplicate_rows' => $import->duplicate_rows + $duplicateRows,
            ]);
        });
    }

    private function finish(Import $import): void
    {
        if ($import->imported_rows > 0) {
            $this->cache->bump($import->organization_id);
        }

        $import->dataSource?->forceFill(['last_synced_at' => now()])->save();

        $this->audit->record('import.finished', $import, [
            'status' => $import->status->value,
            'imported' => $import->imported_rows,
            'failed' => $import->failed_rows,
        ], $import->organization_id, $import->created_by);

        $import->creator?->notify(new ImportFinished($import));
    }
}
