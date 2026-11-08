import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Permission } from '@/types/domain';

/**
 * Client-side permission checks for *presentation only* (hiding actions the
 * server would reject). The server enforces every permission independently.
 */
export function usePermissions() {
    const page = usePage();
    const granted = computed(() => new Set(page.props.tenant?.permissions ?? []));

    return {
        can: (permission: Permission): boolean => granted.value.has(permission),
    };
}
