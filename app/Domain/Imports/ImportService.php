<?php

namespace App\Domain\Imports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Imports\Datasets\FieldDefinition;
use App\Domain\Imports\Readers\SpreadsheetReader;
use App\Enums\DatasetType;
use App\Enums\ImportStatus;
use App\Jobs\ProcessImport;
use App\Models\DataSource;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ImportService
{
    public const DISK = 'local';

    public function __construct(
        private readonly DatasetRegistry $datasets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Store the upload and read its header row. The import starts in Draft
     * until the user confirms a column mapping.
     */
    public function upload(UploadedFile $file, DatasetType $type, ?DataSource $source, User $user): Import
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $path = $file->storeAs('imports/'.Str::uuid(), 'upload.'.$extension, self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored. Please try again.']);
        }

        $absolute = Storage::disk(self::DISK)->path($path);

        try {
            $reader = new SpreadsheetReader($absolute, $extension);
            $headers = $reader->headers();
            $hasRows = $reader->sample(1) !== [];
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->deleteDirectory(dirname($path));
            report($e);

            throw ValidationException::withMessages(['file' => 'The file could not be read. Check that it is a valid CSV or Excel (.xlsx) file.']);
        }

        if ($headers === [] || ! $hasRows) {
            Storage::disk(self::DISK)->deleteDirectory(dirname($path));

            throw ValidationException::withMessages(['file' => 'The file needs a header row and at least one data row.']);
        }

        return Import::query()->create([
            'data_source_id' => $source?->id,
            'dataset_type' => $type,
            'original_filename' => Str::limit($file->getClientOriginalName(), 250, ''),
            'disk' => self::DISK,
            'path' => $path,
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => (string) hash_file('sha256', $absolute),
            'status' => ImportStatus::Draft,
            'headers' => $headers,
            'created_by' => $user->id,
        ]);
    }

    public function reader(Import $import): SpreadsheetReader
    {
        return new SpreadsheetReader(
            Storage::disk($import->disk)->path($import->path),
            pathinfo($import->path, PATHINFO_EXTENSION),
        );
    }

    /**
     * Everything the mapping screen needs: fields, current/suggested mapping,
     * detected date format and a sample of rows.
     *
     * @return array{fields: list<array<string, mixed>>, mapping: array<string, string|null>, dateFormat: string, sample: list<array<string, mixed>>}
     */
    public function mappingContext(Import $import): array
    {
        $dataset = $this->datasets->for($import->dataset_type);
        $fields = $dataset->fields();
        $sample = $this->reader($import)->sample(20);

        $mapping = $import->mappings()->exists()
            ? array_replace(array_fill_keys(array_map(fn ($f) => $f->key, $fields), null), $import->mappingArray())
            : app(MappingSuggester::class)->suggest($fields, $import->headers);

        $dateFormat = $import->options['date_format'] ?? $this->detectDateFormat($fields, $mapping, $sample);

        return [
            'fields' => array_map(fn (FieldDefinition $f) => $f->toArray(), $fields),
            'mapping' => $mapping,
            'dateFormat' => $dateFormat,
            'sample' => array_map(fn (array $row) => array_map(fn ($v) => ValueParser::string($v), $row), $sample),
        ];
    }

    /**
     * Validate and save the mapping, then queue processing.
     *
     * @param  array<string, string|null>  $mapping  field key => header
     */
    public function confirm(Import $import, array $mapping, string $dateFormat): Import
    {
        if ($import->status !== ImportStatus::Draft) {
            throw ValidationException::withMessages(['mapping' => 'This import has already been started.']);
        }

        $fields = $this->datasets->for($import->dataset_type)->fields();
        $errors = [];

        foreach ($fields as $field) {
            $column = $mapping[$field->key] ?? null;
            if ($column !== null && ! in_array($column, $import->headers, true)) {
                $errors["mapping.{$field->key}"] = "Column \"{$column}\" is not in the file.";
            } elseif ($column === null && $field->required) {
                $errors["mapping.{$field->key}"] = "Choose the column that contains {$field->label}.";
            }
        }

        if (! array_key_exists($dateFormat, ValueParser::DATE_FORMATS)) {
            $errors['date_format'] = 'Choose a supported date format.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($import, $mapping, $fields, $dateFormat) {
            $import->mappings()->delete();
            foreach ($fields as $field) {
                if (($mapping[$field->key] ?? null) !== null) {
                    $import->mappings()->create(['source_column' => $mapping[$field->key], 'target_field' => $field->key]);
                }
            }

            $import->update([
                'status' => ImportStatus::Pending,
                'options' => [...($import->options ?? []), 'date_format' => $dateFormat],
            ]);
        });

        $this->audit->record('import.started', $import, [
            'dataset' => $import->dataset_type->value,
            'filename' => $import->original_filename,
        ]);

        ProcessImport::dispatch($import->organization_id, $import->id);

        return $import;
    }

    /**
     * @param  list<FieldDefinition>  $fields
     * @param  array<string, string|null>  $mapping
     * @param  list<array<string, mixed>>  $sample
     */
    private function detectDateFormat(array $fields, array $mapping, array $sample): string
    {
        foreach ($fields as $field) {
            if (in_array($field->type, [FieldDefinition::DATE, FieldDefinition::DATETIME], true) && ($mapping[$field->key] ?? null) !== null) {
                $values = array_map(function ($row) use ($mapping, $field) {
                    $value = ValueParser::string($row[$mapping[$field->key]] ?? null);

                    // Drop a trailing time component before detecting the date part.
                    return $value === null ? null : preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?.*$/', '', $value);
                }, $sample);

                return ValueParser::detectDateFormat($values);
            }
        }

        return 'Y-m-d';
    }
}
