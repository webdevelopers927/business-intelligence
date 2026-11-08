<?php

namespace App\Domain\Audit;

/**
 * Strips secrets from arbitrary metadata before it is persisted or logged.
 */
final class Redactor
{
    private const PATTERN = '/pass(word)?|secret|token|api[_-]?key|credential|authorization|private[_-]?key/i';

    public const MASK = '[redacted]';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $clean[$key] = self::MASK;

                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}
