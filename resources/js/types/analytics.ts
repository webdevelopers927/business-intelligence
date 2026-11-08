import type { AnalyticsFilterState } from '@/composables/useAnalyticsFilters';
import type { Option } from './domain';

export interface Period {
    current: { from: string; to: string; preset: string; label: string; days: number };
    previous: { from: string; to: string; label: string; days: number };
    preset_label: string;
    granularity: 'day' | 'week' | 'month';
    data_through: string | null;
}

export interface Series {
    granularity: 'day' | 'week' | 'month';
    keys: string[];
    labels: string[];
    series: Record<string, (number | null)[]>;
    previous: Record<string, (number | null)[]>;
    previous_labels: string[];
}

export interface BreakdownRow {
    id: number | string;
    label: string;
    color_index: number | null;
    values: Record<string, number | null>;
    previous: Record<string, number | null> | null;
    target?: number | null;
    share?: number | null;
}

export interface TopProduct {
    id: number;
    name: string;
    sku: string | null;
    category: string | null;
    units: number;
    revenue: number;
    gross_profit: number;
    share: number | null;
}

export interface RecentTransaction {
    id: number;
    reference: string;
    occurred_at: string;
    branch: string;
    items: number;
    amount: number;
    status: 'completed' | 'refunded' | 'cancelled' | 'pending';
    channel: string;
}

export interface AnalyticsOptions {
    presets: Option[];
    branches: Option[];
    categories: Option[];
}

export type { AnalyticsFilterState };
