import type { MetricFormat } from '@/lib/metrics';

export type AlertKind = 'metric' | 'import_failed' | 'source_stale';
export type AlertState = 'ok' | 'triggered';

export interface AlertSummary {
    id: number;
    name: string;
    kind: AlertKind;
    condition: string;
    metric_format: MetricFormat | null;
    frequency: 'hourly' | 'daily' | 'weekly';
    state: AlertState;
    is_active: boolean;
    last_value: number | null;
    value_is_percent: boolean;
    last_evaluated_at: string | null;
    last_triggered_at: string | null;
}

export const KIND_LABELS: Record<AlertKind, string> = {
    metric: 'Metric threshold',
    import_failed: 'Import failure',
    source_stale: 'Stale data source',
};

export const FREQUENCY_LABELS: Record<AlertSummary['frequency'], string> = { hourly: 'Hourly', daily: 'Daily', weekly: 'Weekly' };
