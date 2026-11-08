/**
 * Number/date formatting used across KPIs, tables, charts and tooltips.
 * All formatters are pure and locale-explicit so server-rendered numbers and
 * client-rendered numbers never disagree.
 */

const LOCALE = 'en-US';

export function formatNumber(value: number | null | undefined, decimals = 0): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }
    return new Intl.NumberFormat(LOCALE, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

export function formatCurrency(value: number | null | undefined, currency: string, decimals = 0): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }
    return new Intl.NumberFormat(LOCALE, {
        style: 'currency',
        currency,
        currencyDisplay: 'narrowSymbol',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value);
}

/** Compact form for axes and dense cells: 1.2M, 45K. */
export function formatCompact(value: number | null | undefined, currency?: string): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }
    return new Intl.NumberFormat(LOCALE, {
        notation: 'compact',
        maximumFractionDigits: Math.abs(value) >= 1000 ? 1 : 0,
        ...(currency ? { style: 'currency', currency, currencyDisplay: 'narrowSymbol' } : {}),
    }).format(value);
}

/**
 * Signed percent change. `null` means "not computable" (no comparison data or
 * a zero base) and renders as an em dash, never ±∞ or NaN.
 */
export function formatPercentChange(value: number | null | undefined, decimals = 1): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '—';
    }
    const sign = value > 0 ? '+' : value < 0 ? '−' : '';
    return `${sign}${Math.abs(value).toFixed(decimals)}%`;
}

export function formatDate(iso: string | null | undefined, options: Intl.DateTimeFormatOptions = { dateStyle: 'medium' }): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(LOCALE, options).format(date);
}

/** "3 min ago", "2 days ago" — for activity feeds and notifications. */
export function formatRelative(iso: string | null | undefined, now: Date = new Date()): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '—';
    }
    const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];
    const rtf = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' });
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }
    return 'just now';
}
