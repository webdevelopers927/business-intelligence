<?php

namespace App\Domain\Imports;

/**
 * A validated, reference-resolved row ready to persist.
 */
final readonly class PreparedRow
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public int $rowNumber,
        public string $naturalKey,
        public array $data,
    ) {}

    public function withRowNumber(int $rowNumber): self
    {
        return new self($rowNumber, $this->naturalKey, $this->data);
    }
}
