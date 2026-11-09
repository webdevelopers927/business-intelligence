import type { Option } from '@/types/domain';
import type { PaginationMeta } from '@/types/table';

export type ReportColumnType = 'text' | 'date' | 'datetime' | 'number' | 'currency' | 'percent';
export type ExportFormat = 'csv' | 'xlsx' | 'pdf';

export interface ReportColumn {
    key: string;
    label: string;
    type: ReportColumnType;
}

export interface ReportTypeInfo {
    key: string;
    label: string;
    description: string;
    columns: ReportColumn[];
    default_columns: string[];
    default_sort: { column: string; direction: 'asc' | 'desc' };
    default_range: string;
    filters: ('branches' | 'categories')[];
    note: string | null;
}

export interface ReportParameters {
    range: { preset: string; from: string | null; to: string | null };
    filters: { branches: number[]; categories: number[] };
    columns: string[];
    sort: { column: string; direction: 'asc' | 'desc' };
}

export interface ReportPreview {
    columns: ReportColumn[];
    rows: Record<string, string | number | null>[];
    meta: PaginationMeta;
    period: string;
    note: string | null;
}

export interface ReportSchedule {
    frequency: 'daily' | 'weekly' | 'monthly';
    day_of_week: number | null;
    day_of_month: number | null;
    run_at: string;
    format: ExportFormat;
    recipients: number[];
    is_active: boolean;
}

export interface ReportRunRow {
    id: number;
    name: string;
    format: ExportFormat;
    trigger: 'manual' | 'queued' | 'schedule';
    status: 'queued' | 'running' | 'completed' | 'failed';
    row_count: number | null;
    requested_by: string | null;
    created_at: string | null;
    download_url: string | null;
}

export interface AnalyticsOptions {
    presets: Option[];
    branches: Option[];
    categories: Option[];
}

export const FORMAT_OPTIONS: Option<ExportFormat>[] = [
    { value: 'xlsx', label: 'Excel (.xlsx)' },
    { value: 'csv', label: 'CSV' },
    { value: 'pdf', label: 'PDF' },
];
