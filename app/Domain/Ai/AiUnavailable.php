<?php

namespace App\Domain\Ai;

use RuntimeException;

/** A provider failure translated into a message that is safe to show users. */
final class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 503, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
