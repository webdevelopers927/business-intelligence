import type { MetricFormat, Polarity } from '@/lib/metrics';
import type { BreakdownRow, Series } from './analytics';

export type WidgetType = 'kpi' | 'line' | 'area' | 'bar' | 'pie' | 'donut' | 'table' | 'ranked_list';

export interface WidgetConfig {
    metric: string;
    dimension: string | null;
    range: { mode: 'inherit' | 'fixed'; preset: string | null };
    filters: { branches: number[]; categories: number[] };
    comparison: boolean;
    limit: number;
}

export interface Widget {
    id: number;
    type: WidgetType;
    title: string;
    config: WidgetConfig;
    x: number;
    y: number;
    w: number;
    h: number;
    min_w: number;
    min_h: number;
}

export interface CatalogMetric {
    key: string;
    label: string;
    format: MetricFormat;
    polarity: Polarity;
    description: string;
    custom: boolean;
    dimensions: string[];
}

export interface Catalog {
    metrics: CatalogMetric[];
    dimensions: { value: string; label: string }[];
    types: { value: WidgetType; dimensions: (string | null)[] }[];
}

interface WidgetDataBase {
    metric: { key: string; label: string; format: MetricFormat; polarity: Polarity; description: string; custom: boolean };
    period: { label: string; previous_label: string; fixed: boolean };
    ignored_filters: string[];
}

export type WidgetData =
    | (WidgetDataBase & {
          kind: 'kpi';
          kpi: { value: number | null; previous: number | null; change: number | null; change_unit: 'percent' | 'pts' };
          spark: (number | null)[];
      })
    | (WidgetDataBase & { kind: 'series'; series: Series })
    | (WidgetDataBase & { kind: 'breakdown'; dimension: string; rows: BreakdownRow[] });
