<script setup lang="ts">
import { History } from 'lucide-vue-next';
import DataTable from '@/components/data/DataTable.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useTableQuery } from '@/composables/useTableQuery';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import type { Option } from '@/types/domain';
import type { Column, Paginated } from '@/types/table';

interface LogRow {
    id: number;
    action: string;
    area: string;
    actor: string;
    actor_email: string | null;
    subject: string | null;
    metadata: Record<string, unknown> | null;
    ip: string | null;
    created_at: string | null;
}

const props = defineProps<{
    logs: Paginated<LogRow>;
    filters: { area?: string; user?: string | number; from?: string; to?: string };
    areas: Option[];
    members: Option[];
}>();

const { query, goToPage, reset } = useTableQuery(
    {
        area: props.filters.area ?? null,
        user: props.filters.user ? String(props.filters.user) : null,
        from: props.filters.from ?? null,
        to: props.filters.to ?? null,
    },
    ['logs', 'filters'],
);
const hasFilters = () => Boolean(query.area || query.user || query.from || query.to);

const columns: Column[] = [
    { key: 'created_at', label: 'When', width: 'w-40' },
    { key: 'actor', label: 'Who', width: 'w-48' },
    { key: 'action', label: 'Action' },
    { key: 'details', label: 'Details', hideBelow: 'md' },
    { key: 'ip', label: 'IP address', width: 'w-32', hideBelow: 'lg' },
];

/** "report.saved" → "Report saved"; "data_source.updated" → "Data source updated". */
const humanize = (action: string) => {
    const s = action.replace(/[._]/g, ' ');
    return s.charAt(0).toUpperCase() + s.slice(1);
};

/** Flat, readable summary of (already redacted) metadata. */
function details(meta: Record<string, unknown> | null): string {
    if (!meta) return '';
    return Object.entries(meta)
        .filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
        .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`)
        .join(' · ');
}
</script>

<template>
    <AppLayout title="Activity">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <NativeSelect v-model="query.area" :options="[{ value: '', label: 'All areas' }, ...areas]" aria-label="Filter by area" class="w-full sm:w-44" />
            <NativeSelect v-model="query.user" :options="[{ value: '', label: 'All people' }, ...members]" aria-label="Filter by person" class="w-full sm:w-48" />
            <label class="flex items-center gap-1.5 text-xs text-secondary">From<Input v-model="query.from" type="date" class="h-8 w-36" :max="query.to ?? undefined" /></label>
            <label class="flex items-center gap-1.5 text-xs text-secondary">To<Input v-model="query.to" type="date" class="h-8 w-36" :min="query.from ?? undefined" /></label>
            <Button v-if="hasFilters()" variant="ghost" size="sm" @click="reset">Clear filters</Button>
        </div>

        <DataTable :columns="columns" :rows="logs.data" caption="Audit log">
            <template #cell-created_at="{ row }">
                <span class="text-xs text-secondary">{{ formatDate(row.created_at, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</span>
            </template>
            <template #cell-actor="{ row }">
                <span class="text-ink">{{ row.actor }}</span>
                <p v-if="row.actor_email" class="truncate text-[11px] text-muted">{{ row.actor_email }}</p>
            </template>
            <template #cell-action="{ row }">
                <span class="text-ink">{{ humanize(row.action) }}</span>
                <p class="text-[11px] text-muted">{{ row.area }}<template v-if="row.subject"> · {{ row.subject }}</template></p>
            </template>
            <template #cell-details="{ row }">
                <span class="line-clamp-2 text-xs text-secondary" :title="details(row.metadata)">{{ details(row.metadata) || '—' }}</span>
            </template>
            <template #cell-ip="{ row }"><span class="font-mono text-xs text-secondary">{{ row.ip ?? '—' }}</span></template>
            <template #empty>
                <EmptyState v-if="hasFilters()" title="No activity matches these filters" description="Try a different area, person or date range.">
                    <Button size="sm" @click="reset">Clear filters</Button>
                </EmptyState>
                <EmptyState v-else :icon="History" title="No activity yet" description="Sign-ins, settings changes, imports, exports and other actions are recorded here." />
            </template>
        </DataTable>

        <TablePagination :meta="logs.meta" :per-page-options="[]" @page="goToPage" />
    </AppLayout>
</template>
