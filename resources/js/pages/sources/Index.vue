<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Database, Ellipsis, Plus, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import DataTable from '@/components/data/DataTable.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Input from '@/components/ui/Input.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useTableQuery } from '@/composables/useTableQuery';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { DataSource } from '@/types/data';
import type { Option } from '@/types/domain';
import type { Column, Paginated } from '@/types/table';

const props = defineProps<{
    sources: Paginated<DataSource>;
    filters: { search?: string; type?: string; status?: string; sort?: string; direction?: 'asc' | 'desc' };
    types: Option[];
}>();

const { can } = usePermissions();
const { query, sortBy, goToPage, reset } = useTableQuery(
    {
        search: props.filters.search ?? null,
        type: props.filters.type ?? null,
        status: props.filters.status ?? null,
        sort: props.filters.sort ?? null,
        direction: props.filters.direction ?? null,
    },
    ['sources', 'filters'],
);

const statuses: Option[] = [
    { value: 'active', label: 'Active' },
    { value: 'pending', label: 'Not tested' },
    { value: 'error', label: 'Error' },
    { value: 'disabled', label: 'Disabled' },
];

const columns: Column[] = [
    { key: 'name', label: 'Source', sortable: true },
    { key: 'type', label: 'Type', width: 'w-32', hideBelow: 'sm' },
    { key: 'status', label: 'Status', width: 'w-32' },
    { key: 'imports_count', label: 'Imports', align: 'right', width: 'w-20', hideBelow: 'md' },
    { key: 'last_synced_at', label: 'Last synchronized', sortable: true, width: 'w-40', hideBelow: 'md' },
    { key: 'created_at', label: 'Created', sortable: true, width: 'w-32', hideBelow: 'lg' },
    { key: 'actions', label: '', width: 'w-10' },
];

const hasFilters = () => Boolean(query.search || query.type || query.status);
const sortDirection = computed(() => (query.direction === 'asc' || query.direction === 'desc' ? query.direction : null));

const deleting = ref<DataSource | null>(null);
const deleteBusy = ref(false);
function confirmDelete() {
    if (!deleting.value) return;
    deleteBusy.value = true;
    router.delete(route('sources.destroy', { source: deleting.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            deleteBusy.value = false;
            deleting.value = null;
        },
    });
}
</script>

<template>
    <AppLayout title="Data sources">
        <template #actions>
            <Button v-if="can('sources.manage')" variant="primary" :href="route('sources.create')"><Plus />Add source</Button>
        </template>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative w-full sm:w-60">
                <Search class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <Input v-model="query.search" type="search" placeholder="Search sources" aria-label="Search sources" class="pl-8" />
            </div>
            <NativeSelect v-model="query.type" :options="[{ value: '', label: 'All types' }, ...types]" aria-label="Filter by type" class="w-full sm:w-40" />
            <NativeSelect v-model="query.status" :options="[{ value: '', label: 'All statuses' }, ...statuses]" aria-label="Filter by status" class="w-full sm:w-36" />
            <Button v-if="hasFilters()" variant="ghost" size="sm" @click="reset">Clear filters</Button>
        </div>

        <DataTable
            :columns="columns"
            :rows="sources.data"
            :sort="query.sort"
            :direction="sortDirection"
            caption="Data sources"
            @sort="sortBy"
        >
            <template #cell-name="{ row }">
                <Link :href="route('sources.show', { source: row.id })" class="font-medium text-ink hover:text-accent-ink hover:underline">{{ row.name }}</Link>
                <span v-if="row.description" class="ml-2 hidden text-xs text-secondary xl:inline">{{ row.description }}</span>
            </template>
            <template #cell-type="{ row }"><span class="text-secondary">{{ row.type_label }}</span></template>
            <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
            <template #cell-imports_count="{ row }">{{ row.is_file ? row.imports_count : '—' }}</template>
            <template #cell-last_synced_at="{ row }">
                <span class="text-secondary" :title="row.last_synced_at ?? undefined">{{ row.last_synced_at ? formatRelative(row.last_synced_at) : 'Never' }}</span>
            </template>
            <template #cell-created_at="{ row }"><span class="text-xs text-secondary">{{ formatDate(row.created_at) }}</span></template>
            <template #cell-actions="{ row }">
                <Menu width="w-48">
                    <template #trigger>
                        <Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${row.name}`" class="opacity-60 group-hover:opacity-100 focus-visible:opacity-100"><Ellipsis /></Button>
                    </template>
                    <MenuItem @select="router.visit(route('sources.show', { source: row.id }))">View details</MenuItem>
                    <MenuItem v-if="row.is_file && can('imports.create') && row.status === 'active'" @select="router.visit(route('imports.create', { source: row.id }))">Upload file</MenuItem>
                    <template v-if="can('sources.manage')">
                        <MenuItem @select="router.visit(route('sources.edit', { source: row.id }))">Edit</MenuItem>
                        <MenuItem @select="router.post(route('sources.toggle', { source: row.id }), {}, { preserveScroll: true })">
                            {{ row.status === 'disabled' ? 'Enable' : 'Disable' }}
                        </MenuItem>
                        <MenuLabel separator />
                        <MenuItem destructive @select="deleting = row">Delete</MenuItem>
                    </template>
                </Menu>
            </template>
            <template #empty>
                <EmptyState
                    v-if="hasFilters()"
                    title="No sources match these filters"
                    description="Try a different search or clear the filters."
                >
                    <Button size="sm" @click="reset">Clear filters</Button>
                </EmptyState>
                <EmptyState
                    v-else
                    :icon="Database"
                    title="No data sources yet"
                    description="A source groups the data you bring in: a CSV/Excel upload channel, a database or an API."
                >
                    <Button v-if="can('sources.manage')" variant="primary" size="sm" :href="route('sources.create')"><Plus />Add source</Button>
                </EmptyState>
            </template>
        </DataTable>

        <TablePagination :meta="sources.meta" @page="goToPage" />

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name ?? 'source'}?`"
            description="The source and its stored credentials are removed. Data it already imported stays in your analytics and import history."
            confirm-label="Delete source"
            :loading="deleteBusy"
            @update:open="(o: boolean) => !o && (deleting = null)"
            @confirm="confirmDelete"
        />
    </AppLayout>
</template>
