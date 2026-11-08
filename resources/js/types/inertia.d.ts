import type { AuthUser, OrganizationSummary, Tenant, Toast } from './domain';
import type { route } from '@/lib/route';
import type { Config as ZiggyConfig } from 'ziggy-js';

export interface SharedProps {
    [key: string]: unknown;
    app: { name: string };
    auth: {
        user: AuthUser | null;
        organizations: OrganizationSummary[];
    };
    tenant: Tenant | null;
    flash: { toast: Toast | null };
    errors: Record<string, string>;
    ziggy: ZiggyConfig & { location: string };
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedProps;
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        route: typeof route;
    }
}
