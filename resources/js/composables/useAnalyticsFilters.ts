import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

export interface AnalyticsFilterState {
    range: string;
    from: string;
    to: string;
    branches: number[];
    categories: number[];
}

/**
 * Dashboard/explorer filters live in the URL (shareable, survive reloads).
 * Applying navigates to the same page with new params; KPIs render with the
 * response and deferred panels reload behind their skeletons.
 */
export function useAnalyticsFilters(initial: AnalyticsFilterState) {
    const state = reactive<AnalyticsFilterState>({ ...initial, branches: [...initial.branches], categories: [...initial.categories] });

    function apply(patch: Partial<AnalyticsFilterState> = {}) {
        Object.assign(state, patch);
        const params: Record<string, string> = { range: state.range };
        if (state.range === 'custom') {
            params.from = state.from;
            params.to = state.to;
        }
        if (state.branches.length) params.branches = state.branches.join(',');
        if (state.categories.length) params.categories = state.categories.join(',');

        router.get(window.location.pathname, params, { preserveScroll: true, preserveState: true });
    }

    function reset() {
        apply({ range: 'this_month', branches: [], categories: [] });
    }

    return { state, apply, reset };
}

/** Query string for links that should carry the current filter context. */
export function filterParams(state: AnalyticsFilterState): Record<string, string> {
    const params: Record<string, string> = { range: state.range };
    if (state.range === 'custom') {
        params.from = state.from;
        params.to = state.to;
    }
    if (state.branches.length) params.branches = state.branches.join(',');
    if (state.categories.length) params.categories = state.categories.join(',');
    return params;
}
