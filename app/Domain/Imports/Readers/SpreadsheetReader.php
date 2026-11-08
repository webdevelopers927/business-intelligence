<?php

namespace App\Domain\Imports\Readers;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams rows from CSV or XLSX with constant memory (openspout).
 * The first non-empty row is the header row; headers are normalized to be
 * non-empty and unique so they can key associative rows.
 */
final class SpreadsheetReader
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(
        private readonly string $path,
        private readonly string $extension,
    ) {}

    /** @return list<string> */
    public function headers(): array
    {
        foreach ($this->rawRows() as $cells) {
            if (! self::isBlank($cells)) {
                return self::normalizeHeaders($cells);
            }
        }

        return [];
    }

    /**
     * Data rows as header => value, with their 1-based spreadsheet row number
     * (header = row 1), so error reports point at the row the user sees.
     *
     * @return Generator<int, array<string, mixed>> row number => values
     */
    public function rows(): Generator
    {
        $headers = null;
        $rowNumber = 0;

        foreach ($this->rawRows() as $cells) {
            $rowNumber++;
            if (self::isBlank($cells)) {
                continue;
            }

            if ($headers === null) {
                $headers = self::normalizeHeaders($cells);

                continue;
            }

            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $cells[$i] ?? null;
            }

            yield $rowNumber => $row;
        }
    }

    /** @return list<array<string, mixed>> */
    public function sample(int $limit = 20): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function countRows(): int
    {
        $count = 0;
        foreach ($this->rows() as $_) {
            $count++;
        }

        return $count;
    }

    /** @return Generator<int, list<mixed>> */
    private function rawRows(): Generator
    {
        $reader = $this->open();

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_values($row->toArray());
                }

                break; // first worksheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function open(): CsvReader|XlsxReader
    {
        // Empty rows are preserved (and skipped in rows()) so reported row
        // numbers match what the user sees in their spreadsheet.
        $reader = match (strtolower($this->extension)) {
            'csv', 'txt' => new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $this->detectDelimiter())),
            'xlsx' => new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)),
            default => throw new RuntimeException("Unsupported file type: {$this->extension}"),
        };

        $reader->open($this->path);

        return $reader;
    }

    private function detectDelimiter(): string
    {
        $handle = fopen($this->path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle, 16384);
        if ($handle !== false) {
            fclose($handle);
        }

        $best = ',';
        $bestCount = 0;
        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $bestCount) {
                [$best, $bestCount] = [$delimiter, $count];
            }
        }

        return $best;
    }

    /** @param list<mixed> $cells */
    private static function isBlank(array $cells): bool
    {
        return array_all($cells, fn ($c) => $c === null || (is_string($c) && trim($c) === ''));
    }

    /**
     * @param  list<mixed>  $cells
     * @return list<string>
     */
    public static function normalizeHeaders(array $cells): array
    {
        $seen = [];
        $headers = [];

        foreach ($cells as $i => $cell) {
            $name = trim((string) (is_scalar($cell) ? $cell : ''));
            $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name; // UTF-8 BOM
            if ($name === '') {
                $name = 'Column '.($i + 1);
            }

            $unique = $name;
            $n = 2;
            while (isset($seen[mb_strtolower($unique)])) {
                $unique = "{$name} ({$n})";
                $n++;
            }

            $seen[mb_strtolower($unique)] = true;
            $headers[] = $unique;
        }

        return $headers;
    }
}
