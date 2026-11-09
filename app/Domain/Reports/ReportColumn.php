<?php

namespace App\Domain\Reports;

/**
 * A report column. `type` drives alignment, formatting and how the value is
 * written to CSV (raw), XLSX (typed cell) and PDF (formatted text).
 */
final readonly class ReportColumn
{
    /** @param 'text'|'date'|'datetime'|'number'|'currency'|'percent' $type */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = 'text',
    ) {}

    public function isNumeric(): bool
    {
        return in_array($this->type, ['number', 'currency', 'percent'], true);
    }

    /** @return array{key: string, label: string, type: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
