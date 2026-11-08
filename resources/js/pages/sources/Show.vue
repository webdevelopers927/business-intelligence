<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, FileUp, Pencil, PlugZap } from 'lucide-vue-next';
import { ref } from 'vue';
import DataTable from '@/components/data/DataTable.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import { http, HttpError } from '@/lib/http';
import { route } from '@/lib/route';
import type { DataSource, ImportSummary } from '@/types/data';
import type { Column, Paginated } from '@/types/table';

const props = defineProps<{ source: DataSource; imports: Paginated<ImportSummary> }>();
const { can } = usePermissions();

const current = ref<DataSource>(props.source);
const testing = ref(false);
const testResult = ref<{ ok: boolean; message: string } | null>(null);

async function testConnection() {
    testing.value = true;
    testResult.value = null;
    try {
        const res = await http.post<{ data: { ok: boolean; message: string; source: DataSource } }>(route('sources.test', { source: props.source.id }));
        testResult.value = { ok: res.data.ok, message: res.data.message };
        current.value = res.data.source;
    } catch (e) {
        testResult.value = { ok: false, message: e instanceof HttpError ? e.message : 'The test could not be run.' };
    } finally {
        testing.value = false;
    }
}

const configRows = () => {
    const c = current.value.config;
    if (current.value.type === 'rest') {
        return [
            ['URL', c.url],
            ['Records path', c.records_path || '(response root)'],
            ['Auth header', c.auth_header || 'Authorization'],
            ['Token', current.value.has_credentials ? 'Stored (encrypted)' : 'None'],
        ];
    }
    return [
        ['Host', `${c.host}:${c.port}`],
        ['Database', c.database],
        ['Table or view', c.table || '—'],
        ['Username', c.username],
        ['Password', current.value.has_credentials ? 'Stored (encrypted)' : 'Not set'],
    ];
};

const columns: Column[] = [
    { key: 'original_filename', label: 'File' },
    { key: 'dataset_label', label: 'Dataset', width: 'w-40', hideBelow: 'sm' },
    { key: 'imported_rows', label: 'Imported', align: 'right', width: 'w-24' },
    { key: 'failed_rows', label: 'Failed', align: 'right', width: 'w-20', hideBelow: 'md' },
    { key: 'created_at', label: 'Uploaded', width: 'w-36', hideBelow: 'md' },
    { key: 'status', label: 'Status', width: 'w-44' },
];

function page(p: number) {
    router.get(window.location.pathname, { page: p }, { only: ['imports'], preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout :title="current.name" :breadcrumbs="[{ label: 'Data sources', href: route('sources.index') }]">
        <template #actions>
            <Button v-if="can('sources.manage')" :href="route('sources.edit', { source: current.id })"><Pencil />Edit</Button>
            <Button v-if="current.is_file && can('imports.create') && current.status === 'active'" variant="primary" :href="route('imports.create', { source: current.id })">
                <FileUp />Upload file
            </Button>
            <Button v-if="!current.is_file && can('sources.manage')" variant="primary" :loading="testing" @click="testConnection"><PlugZap />Test connection</Button>
        </template>

        <!-- Summary band -->
        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-4">
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Status</dt>
                <dd class="mt-1.5"><StatusBadge :status="current.status" :label="current.status_label" /></dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Type</dt>
                <dd class="mt-1.5 text-sm text-ink">{{ current.type_label }}</dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Last synchronized</dt>
                <dd class="mt-1.5 text-sm text-ink" :title="current.last_synced_at ?? undefined">{{ current.last_synced_at ? formatRelative(current.last_synced_at) : 'Never' }}</dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Created</dt>
                <dd class="mt-1.5 text-sm text-ink">{{ formatDate(current.created_at) }}<span v-if="current.created_by" class="text-secondary"> by {{ current.created_by }}</span></dd>
            </div>
        </dl>
        <p v-if="current.description" class="mt-3 text-sm text-secondary">{{ current.description }}</p>

        <!-- Connection sources -->
        <section v-if="!current.is_file" class="mt-8 grid gap-6 lg:grid-cols-[1fr_1fr]" aria-labelledby="conn-heading">
            <div>
                <h2 id="conn-heading" class="border-b border-border pb-2 text-sm font-semibold text-ink">Connection</h2>
                <dl class="divide-y divide-border text-sm">
                    <div v-for="[label, value] in configRows()" :key="label" class="grid grid-cols-[140px_1fr] gap-3 py-2">
                        <dt class="text-secondary">{{ label }}</dt>
                        <dd class="font-mono text-xs leading-5 break-all text-ink">{{ value }}</dd>
                    </div>
                </dl>
            </div>
            <div>
                <h2 class="border-b border-border pb-2 text-sm font-semibold text-ink">Connection test</h2>
                <div class="py-3 text-sm">
                    <p v-if="testResult" :class="['flex items-start gap-2', testResult.ok ? 'text-positive' : 'text-negative']" role="status">
                        <component :is="testResult.ok ? CircleCheck : CircleAlert" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ testResult.message }}</span>
                    </p>
                    <p v-else-if="current.last_error" class="flex items-start gap-2 text-negative">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ current.last_error }}</span>
                    </p>
                    <p v-else-if="current.last_tested_at" class="text-secondary">Last test passed {{ formatRelative(current.last_tested_at) }}.</p>
                    <p v-else class="text-secondary">Not tested yet. Run a test to verify ORBIT can reach this source.</p>
                    <p class="mt-3 text-xs text-secondary">
                        Scheduled synchronization from database and API sources is not available yet; it will read from the configured
                        {{ current.type === 'rest' ? 'endpoint' : 'table' }} through the same validated import pipeline. Until then, export the data to CSV or Excel and upload it.
                    </p>
                </div>
            </div>
        </section>

        <!-- File sources: synchronization history = uploads -->
        <section v-else class="mt-8" aria-labelledby="history-heading">
            <h2 id="history-heading" class="pb-2 text-sm font-semibold text-ink">
                Upload history <span class="num ml-1 font-normal text-secondary">{{ formatNumber(imports.meta.total) }}</span>
            </h2>
            <DataTable :columns="columns" :rows="imports.data" caption="Upload history">
                <template #cell-original_filename="{ row }">
                    <Link :href="route('imports.show', { import: row.id })" class="font-medium text-ink hover:text-accent-ink hover:underline">{{ row.original_filename }}</Link>
                </template>
                <template #cell-dataset_label="{ row }"><span class="text-secondary">{{ row.dataset_label }}</span></template>
                <template #cell-imported_rows="{ row }">{{ formatNumber(row.imported_rows) }}</template>
                <template #cell-failed_rows="{ row }"><span :class="row.failed_rows ? 'text-negative' : 'text-secondary'">{{ formatNumber(row.failed_rows) }}</span></template>
                <template #cell-created_at="{ row }"><span class="text-xs text-secondary">{{ formatDate(row.created_at, { dateStyle: 'medium', timeStyle: 'short' }) }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" :label="row.status_label" /></template>
                <template #empty>
                    <EmptyState :icon="FileUp" title="No uploads yet" description="Files uploaded through this source will be listed here with their results.">
                        <Button v-if="can('imports.create') && current.status === 'active'" size="sm" variant="primary" :href="route('imports.create', { source: current.id })">Upload file</Button>
                    </EmptyState>
                </template>
            </DataTable>
            <TablePagination :meta="imports.meta" :per-page-options="[10]" @page="page" />
        </section>
    </AppLayout>
</template>
