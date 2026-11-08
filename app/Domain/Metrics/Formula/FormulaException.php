<?php

namespace App\Domain\Metrics\Formula;

use InvalidArgumentException;

/**
 * A formula could not be parsed or validated. `position` (0-based character
 * offset) lets the editor point at the problem.
 */
final class FormulaException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly ?int $position = null)
    {
        parent::__construct($message);
    }
}
