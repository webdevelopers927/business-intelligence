import { formatCompact, formatCurrency, formatNumber, formatPercentChange } from './format';

export type MetricFormat = 'currency' | 'number' | 'percent';
export type Polarity = 'up_good' | 'up_bad' | 'neutral';

export interface Kpi {
    key: string;
    label: string;
    format: MetricFormat;
    polarity: Polarity;
    description: string;
    value: number | null;
    previous: number | null;
    change: number | null;
    /** Ratios (margins) change in percentage points, everything else in percent. */
    change_unit?: 'percent' | 'pts';
    ignored_filters?: string[];
}

/** Currency KPIs are compact from 1M up, so values in one band read alike. */
export function kpiValue(kpi: Pick<Kpi, 'value' | 'format'>, currency: string): string {
    const compact = kpi.format === 'currency' ? Math.abs(kpi.value ?? 0) >= 1_000_000 : Math.abs(kpi.value ?? 0) >= 100_000;
    return formatMetric(kpi.value, kpi.format, currency, compact);
}

/** Format a metric value for display; compact for dense cells and axes. */
export function formatMetric(value: number | null | undefined, format: MetricFormat, currency: string, compact = false): string {
    if (value === null || value === undefined || !Number.isFinite(value)) return '—';
    if (format === 'percent') return `${formatNumber(value, 1)}%`;
    if (format === 'currency') return compact ? formatCompact(value, currency) : formatCurrency(value, currency);
    return compact ? formatCompact(value) : formatNumber(value, Number.isInteger(value) ? 0 : 1);
}

/**
 * Direction is colored by business polarity, not sign: expenses up is bad,
 * revenue down is bad, neutral metrics stay gray (DESIGN_SYSTEM §2).
 */
export function changeTone(change: number | null, polarity: Polarity): 'positive' | 'negative' | 'neutral' {
    if (change === null || Math.abs(change) < 0.05 || polarity === 'neutral') return 'neutral';
    const good = polarity === 'up_good' ? change > 0 : change < 0;
    return good ? 'positive' : 'negative';
}

export { formatPercentChange };
