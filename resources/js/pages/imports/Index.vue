<script setup lang="ts">
import { Link, usePoll } from '@inertiajs/vue3';
import { FileUp, Plus, Search } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import DataTable from '@/components/data/DataTable.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useTableQuery } from '@/composables/useTableQuery';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { route } from '@/lib/route';
import type { ImportSummary } from '@/types/data';
import type { Option } from '@/types/domain';
import type { Column, Paginated } from '@/types/table';

const props = defineProps<{
    imports: Paginated<ImportSummary>;
    filters: { search?: string; status?: string; dataset?: string };
    datasets: Option[];
    statuses: Option[];
}>();

const { can } = usePermissions();
const { query, goToPage, reset } = useTableQuery(
    { search: props.filters.search ?? null, status: props.filters.status ?? null, dataset: props.filters.dataset ?? null },
    ['imports', 'filters'],
);
const hasFilters = () => Boolean(query.search || query.status || query.dataset);

// Keep running imports live while they're on screen.
const anyActive = computed(() => props.imports.data.some((i) => i.status === 'pending' || i.status === 'processing'));
const { start, stop } = usePoll(2000, { only: ['imports'] }, { autoStart: false });
watch(anyActive, (active) => (active ? start() : stop()), { immediate: true });

const columns: Column[] = [
    { key: 'original_filename', label: 'File' },
    { key: 'dataset_label', label: 'Dataset', width: 'w-40', hideBelow: 'sm' },
    { key: 'source', label: 'Source', width: 'w-40', hideBelow: 'lg' },
    { key: 'total_rows', label: 'Rows', align: 'right', width: 'w-20', hideBelow: 'md' },
    { key: 'imported_rows', label: 'Imported', align: 'right', width: 'w-24' },
    { key: 'failed_rows', label: 'Failed', align: 'right', width: 'w-20', hideBelow: 'sm' },
    { key: 'started_at', label: 'Started', width: 'w-36', hideBelow: 'lg' },
    { key: 'completed_at', label: 'Completed', width: 'w-36', hideBelow: 'lg' },
    { key: 'status', label: 'Status', width: 'w-44' },
];

const dt = (iso: string | null) => (iso ? formatDate(iso, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
</script>

<template>
    <AppLayout title="Imports">
        <template #actions>
            <Button v-if="can('imports.create')" variant="primary" :href="route('imports.create')"><Plus />New import</Button>
        </template>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative w-full sm:w-60">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <Input v-model="query.search" type="search" placeholder="Search file names" aria-label="Search file names" class="pl-8" />
            </div>
            <NativeSelect v-model="query.dataset" :options="[{ value: '', label: 'All datasets' }, ...datasets]" aria-label="Filter by dataset" class="w-full sm:w-44" />
            <NativeSelect v-model="query.status" :options="[{ value: '', label: 'All statuses' }, ...statuses]" aria-label="Filter by status" class="w-full sm:w-48" />
            <Button v-if="hasFilters()" variant="ghost" size="sm" @click="reset">Clear filters</Button>
        </div>

        <DataTable :columns="columns" :rows="imports.data" caption="Import history">
            <template #cell-original_filename="{ row }">
                <Link
                    :href="row.status === 'draft' ? route('imports.mapping', { import: row.id }) : route('imports.show', { import: row.id })"
                    class="font-medium text-ink hover:text-accent-ink hover:underline"
                >
                    {{ row.original_filename }}
                </Link>
                <span v-if="row.created_by" class="ml-2 hidden text-xs text-secondary xl:inline">by {{ row.created_by }}</span>
            </template>
            <template #cell-dataset_label="{ row }"><span class="text-secondary">{{ row.dataset_label }}</span></template>
            <template #cell-source="{ row }"><span class="text-secondary">{{ row.source?.name ?? 'Direct upload' }}</span></template>
            <template #cell-total_rows="{ row }">{{ formatNumber(row.total_rows) }}</template>
            <template #cell-imported_rows="{ row }">
                <template v-if="row.status === 'processing'">{{ row.progress }}%</template>
                <template v-else>{{ row.status === 'draft' || row.status === 'pending' ? '—' : formatNumber(row.imported_rows) }}</template>
            </template>
            <template #cell-failed_rows="{ row }">
                <span :class="row.failed_rows ? 'text-negative' : 'text-secondary'">{{ row.status === 'draft' ? '—' : formatNumber(row.failed_rows) }}</span>
            </template>
            <template #cell-started_at="{ row }"><span class="text-xs text-secondary">{{ dt(row.started_at) }}</span></template>
            <template #cell-completed_at="{ row }"><span class="text-xs text-secondary">{{ dt(row.completed_at) }}</span></template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            <template #empty>
                <EmptyState v-if="hasFilters()" title="No imports match these filters" description="Try a different search or clear the filters.">
                    <Button size="sm" @click="reset">Clear filters</Button>
                </EmptyState>
                <EmptyState v-else :icon="FileUp" title="No imports yet" description="Upload a CSV or Excel file to bring sales, expenses, inventory or people data into ORBIT.">
                    <Button v-if="can('imports.create')" variant="primary" size="sm" :href="route('imports.create')"><Plus />New import</Button>
                </EmptyState>
            </template>
        </DataTable>

        <TablePagination :meta="imports.meta" @page="goToPage" />
    </AppLayout>
</template>
