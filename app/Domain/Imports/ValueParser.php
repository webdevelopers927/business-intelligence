<?php

namespace App\Domain\Imports;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Lenient, explicit parsing of spreadsheet cell values.
 * Every method returns null when the value cannot be interpreted; callers
 * turn that into a row error instead of guessing.
 */
final class ValueParser
{
    /** Date formats offered to the user; detection picks the first that parses every sample. */
    public const DATE_FORMATS = [
        'Y-m-d' => '2026-03-31',
        'd/m/Y' => '31/03/2026',
        'm/d/Y' => '03/31/2026',
        'd-m-Y' => '31-03-2026',
        'd.m.Y' => '31.03.2026',
        'Y/m/d' => '2026/03/31',
    ];

    public static function string(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Accepts "1,234.50", "Rs 1,234", "PKR 99", "  12 ", "(150.00)" (negative).
     * Rejects anything with letters left after stripping a currency label.
     */
    public static function decimal(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        $s = self::string($value);
        if ($s === null) {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m) === 1) {
            $negative = true;
            $s = $m[1];
        }

        $s = preg_replace('/^(rs\.?|pkr|usd|\$|€|£|₨)\s*/i', '', $s) ?? $s;
        $s = str_replace([',', ' ', "\u{00A0}"], '', $s);

        if (preg_match('/^-?\d+(\.\d+)?$/', $s) !== 1) {
            return null;
        }

        $number = (float) $s;

        return $negative ? -$number : $number;
    }

    /** Parse a date (time part ignored) using the import's chosen format. */
    public static function date(mixed $value, string $format): ?CarbonImmutable
    {
        return self::datetime($value, $format)?->startOfDay();
    }

    /**
     * Parse a date, optionally followed by a time ("H:i" or "H:i:s").
     * XLSX cells already arrive as DateTimeInterface.
     */
    public static function datetime(mixed $value, string $format): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $s = self::string($value);
        if ($s === null) {
            return null;
        }

        foreach ([$format.' H:i:s', $format.' H:i', $format, $format.'\TH:i:s', $format.'\TH:i:sP'] as $candidate) {
            // Native parser: returns false instead of throwing (Carbon's strict mode throws).
            $parsed = DateTimeImmutable::createFromFormat('!'.$candidate, $s);
            // createFromFormat silently overflows (31/02 → 03/03); require a round trip.
            if ($parsed !== false && $parsed->format($candidate) === $s) {
                return CarbonImmutable::instance($parsed);
            }
        }

        return null;
    }

    /**
     * Pick the date format that parses every non-empty sample. Ambiguous
     * day/month samples (all ≤ 12) resolve to the first matching format in
     * DATE_FORMATS order; the user can override it on the mapping screen.
     *
     * @param  list<mixed>  $samples
     */
    public static function detectDateFormat(array $samples): string
    {
        $values = array_values(array_filter($samples, fn ($v) => $v !== null && $v !== ''));

        foreach (array_keys(self::DATE_FORMATS) as $format) {
            if ($values !== [] && array_all($values, fn ($v) => self::date($v, $format) !== null)) {
                return $format;
            }
        }

        return 'Y-m-d';
    }
}
