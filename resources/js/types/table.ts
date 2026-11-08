export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface Paginated<T> {
    data: T[];
    meta: PaginationMeta;
}

export interface Column {
    key: string;
    label: string;
    /** Numbers, currency and percentages are right-aligned (DESIGN_SYSTEM §9). */
    align?: 'left' | 'right' | 'center';
    sortable?: boolean;
    /** Tailwind width class, e.g. "w-32". */
    width?: string;
    /** Hide below this breakpoint to keep tables readable on small screens. */
    hideBelow?: 'sm' | 'md' | 'lg';
}
