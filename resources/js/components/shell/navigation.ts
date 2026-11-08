import { Building2, Compass, Database, FileUp, LayoutDashboard, LayoutGrid, type LucideIcon, Sigma, Users } from 'lucide-vue-next';
import type { Permission } from '@/types/domain';

export interface NavItem {
    label: string;
    route: string;
    /** Route-name pattern used for the active state (e.g. "settings.members.*"). */
    match: string;
    icon: LucideIcon;
    permission?: Permission;
}

export interface NavGroup {
    label: string;
    items: NavItem[];
}

/**
 * Sidebar information architecture (docs/ROUTES.md §4). Items whose routes
 * are not registered yet are filtered out at render time, so the sidebar
 * never shows a link to a module that hasn't shipped.
 */
export const primaryNavigation: NavGroup[] = [
    {
        label: 'Overview',
        items: [
            { label: 'Executive', route: 'dashboard', match: 'dashboard', icon: LayoutGrid },
            { label: 'Dashboards', route: 'dashboards.index', match: 'dashboards.*', icon: LayoutDashboard, permission: 'dashboards.view' },
            { label: 'Explore', route: 'explore', match: 'explore', icon: Compass },
        ],
    },
    {
        label: 'Analysis',
        items: [{ label: 'Metrics', route: 'metrics.index', match: 'metrics.*', icon: Sigma }],
    },
    {
        label: 'Data',
        items: [
            { label: 'Sources', route: 'sources.index', match: 'sources.*', icon: Database, permission: 'sources.view' },
            { label: 'Imports', route: 'imports.index', match: 'imports.*', icon: FileUp, permission: 'sources.view' },
        ],
    },
];

export const settingsNavigation: NavItem[] = [
    {
        label: 'Organization',
        route: 'settings.organization.edit',
        match: 'settings.organization.*',
        icon: Building2,
        permission: 'org.settings.manage',
    },
    {
        label: 'Members',
        route: 'settings.members.index',
        match: 'settings.members.*',
        icon: Users,
        permission: 'members.manage',
    },
];
