/** Wire contracts for data sources and imports (app/Http/Resources). */

export type DataSourceType = 'csv' | 'xlsx' | 'mysql' | 'postgres' | 'rest';
export type DataSourceStatus = 'pending' | 'active' | 'error' | 'disabled';
export type ImportStatus = 'draft' | 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed';
export type DatasetType = 'sales' | 'expenses' | 'products' | 'inventory' | 'employees' | 'branches' | 'departments';

export interface DataSource {
    id: number;
    name: string;
    type: DataSourceType;
    type_label: string;
    is_file: boolean;
    description: string | null;
    status: DataSourceStatus;
    status_label: string;
    config: Partial<{
        host: string;
        port: number;
        database: string;
        username: string;
        table: string;
        url: string;
        auth_header: string;
        records_path: string;
    }>;
    has_credentials: boolean;
    last_synced_at: string | null;
    last_tested_at: string | null;
    last_error: string | null;
    created_by?: string | null;
    imports_count?: number;
    created_at: string | null;
}

export interface ImportSummary {
    id: number;
    original_filename: string;
    dataset_type: DatasetType;
    dataset_label: string;
    status: ImportStatus;
    status_label: string;
    size_bytes: number;
    total_rows: number | null;
    processed_rows: number;
    imported_rows: number;
    failed_rows: number;
    duplicate_rows: number;
    progress: number;
    failure_reason: string | null;
    source?: { id: number; name: string } | null;
    created_by?: string | null;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

export interface ImportField {
    key: string;
    label: string;
    type: 'string' | 'decimal' | 'date' | 'datetime' | 'enum';
    required: boolean;
    options: string[];
    hint: string | null;
}

export interface ImportRowError {
    id: number;
    row_number: number;
    field: string | null;
    code: string;
    message: string;
}
