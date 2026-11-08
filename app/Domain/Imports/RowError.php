<?php

namespace App\Domain\Imports;

/**
 * Why a row was rejected. Codes are stable identifiers shown in the failed
 * rows export; messages are written for the person fixing the file.
 */
final readonly class RowError
{
    public const REQUIRED = 'required';

    public const INVALID_DATE = 'invalid_date';

    public const INVALID_NUMBER = 'invalid_number';

    public const INVALID_VALUE = 'invalid_value';

    public const TOO_LONG = 'too_long';

    public const OUT_OF_RANGE = 'out_of_range';

    public const UNKNOWN_REFERENCE = 'unknown_reference';

    public const DUPLICATE = 'duplicate';

    public const DUPLICATE_IN_FILE = 'duplicate_in_file';

    public function __construct(
        public string $code,
        public string $message,
        public ?string $field = null,
    ) {}

    public function isDuplicate(): bool
    {
        return $this->code === self::DUPLICATE || $this->code === self::DUPLICATE_IN_FILE;
    }
}
