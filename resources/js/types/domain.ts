/** Wire contracts mirrored from PHP enums and shared props. Keep in sync with app/Enums. */

export type Role = 'owner' | 'admin' | 'analyst' | 'viewer';
export type Theme = 'system' | 'light' | 'dark';

export type Permission =
    | 'org.settings.manage'
    | 'org.billing.view'
    | 'members.manage'
    | 'members.manage_owners'
    | 'sources.view'
    | 'sources.manage'
    | 'imports.create'
    | 'metrics.manage'
    | 'dashboards.create'
    | 'dashboards.view'
    | 'reports.create'
    | 'reports.export'
    | 'alerts.manage'
    | 'audit.view'
    | 'ai.use';

export interface Option<T extends string = string> {
    value: T;
    label: string;
    description?: string;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    theme: Theme;
}

export interface OrganizationSummary {
    id: number;
    name: string;
    slug: string;
    role: Role;
}

export interface Tenant {
    id: number;
    name: string;
    slug: string;
    currency: string;
    timezone: string;
    role: Role | null;
    role_label: string | null;
    permissions: Permission[];
    unread_notifications: number;
}

export interface Toast {
    id: string;
    type: 'success' | 'error' | 'info';
    message: string;
}

export interface AppNotification {
    id: string;
    kind: string;
    title: string;
    body: string;
    url: string | null;
    read_at: string | null;
    created_at: string | null;
}
