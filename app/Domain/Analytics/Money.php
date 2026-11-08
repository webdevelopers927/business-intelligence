<?php

namespace App\Domain\Analytics;

/**
 * Server-side number formatting for generated text (insights, notifications).
 * Charts and tables format on the client from raw numbers.
 */
final class Money
{
    public static function compact(float $value, string $currency): string
    {
        $symbol = $currency === 'PKR' ? 'Rs' : $currency;
        $abs = abs($value);
        $sign = $value < 0 ? '−' : '';

        return $sign.$symbol.' '.match (true) {
            $abs >= 1_000_000_000 => number_format($abs / 1_000_000_000, 1).'B',
            $abs >= 1_000_000 => number_format($abs / 1_000_000, 1).'M',
            $abs >= 1_000 => number_format($abs / 1_000, 1).'K',
            default => number_format($abs),
        };
    }

    /** Unsigned percent for sentences that already say up/down: "up 12.4%". */
    public static function magnitude(?float $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format(abs($value), $decimals).'%';
    }

    public static function percent(?float $value, int $decimals = 1): string
    {
        if ($value === null) {
            return '—';
        }

        return ($value > 0 ? '+' : ($value < 0 ? '−' : '')).number_format(abs($value), $decimals).'%';
    }
}
