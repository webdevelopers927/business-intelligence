import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { reactive, watch } from 'vue';

type QueryValue = string | number | null | undefined;

/**
 * Table filters/sort/search live in the URL (shareable, survive reload).
 * Changing any value reloads only the given props, preserving state & scroll;
 * text search is debounced. Any filter change resets to page 1.
 */
export function useTableQuery<T extends Record<string, QueryValue>>(initial: T, only: string[]) {
    const query = reactive({ ...initial }) as T;

    function apply(extra: Record<string, QueryValue> = {}) {
        const params: Record<string, QueryValue> = { ...query, ...extra };
        const cleaned = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(window.location.pathname, cleaned, { only, preserveState: true, preserveScroll: true, replace: true });
    }

    const debounced = useDebounceFn(() => apply(), 300);

    watch(
        () => ({ ...query }),
        (now, before) => {
            const searchOnly = Object.keys(now).every((k) => k === 'search' || now[k] === before[k]);
            if (searchOnly) {
                debounced();
            } else {
                apply();
            }
        },
    );

    function sortBy(column: string) {
        const q = query as Record<string, QueryValue>;
        if (q.sort === column) {
            q.direction = q.direction === 'asc' ? 'desc' : 'asc';
        } else {
            q.sort = column;
            q.direction = 'asc';
        }
    }

    function goToPage(page: number, perPage?: number) {
        apply({ page, ...(perPage ? { per_page: perPage } : {}) });
    }

    function reset() {
        for (const key of Object.keys(query)) {
            (query as Record<string, QueryValue>)[key] = null;
        }
    }

    return { query, sortBy, goToPage, reset };
}
