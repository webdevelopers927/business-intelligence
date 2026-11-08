<script setup lang="ts">
import { Link, router, usePoll } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Download, FileUp } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import DataTable from '@/components/data/DataTable.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import ImportSteps from '@/components/imports/ImportSteps.vue';
import Button from '@/components/ui/Button.vue';
import { usePermissions } from '@/composables/usePermissions';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { route } from '@/lib/route';
import type { ImportRowError, ImportSummary } from '@/types/data';
import type { Column, Paginated } from '@/types/table';

const props = defineProps<{
    import: ImportSummary;
    mappings: { source_column: string; target_field: string }[];
    errors: Paginated<ImportRowError>;
    errorSummary: Record<string, number>;
}>();

const { can } = usePermissions();
const imp = computed(() => props.import);
const active = computed(() => imp.value.status === 'pending' || imp.value.status === 'processing');

const { start, stop } = usePoll(1500, { only: ['import', 'errors', 'errorSummary'] }, { autoStart: false });
watch(active, (a) => (a ? start() : stop()), { immediate: true });

const duration = computed(() => {
    if (!imp.value.started_at || !imp.value.completed_at) return null;
    const s = Math.max(0, (new Date(imp.value.completed_at).getTime() - new Date(imp.value.started_at).getTime()) / 1000);
    return s < 60 ? `${s.toFixed(1)} s` : `${Math.floor(s / 60)} min ${Math.round(s % 60)} s`;
});

const codeLabels: Record<string, string> = {
    required: 'Missing required value',
    invalid_date: 'Invalid date',
    invalid_number: 'Invalid number',
    invalid_value: 'Value not allowed',
    too_long: 'Value too long',
    out_of_range: 'Out of range',
    unknown_reference: 'Unknown reference',
    duplicate: 'Already imported',
    duplicate_in_file: 'Repeated in file',
};

const errorColumns: Column[] = [
    { key: 'row_number', label: 'Row', align: 'right', width: 'w-16' },
    { key: 'code', label: 'Problem', width: 'w-44' },
    { key: 'field', label: 'Field', width: 'w-36', hideBelow: 'sm' },
    { key: 'message', label: 'Details' },
];

function errorsPage(page: number) {
    router.get(window.location.pathname, { errors_page: page }, { only: ['errors'], preserveState: true, preserveScroll: true });
}

const fmt = (iso: string | null) => (iso ? formatDate(iso, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
</script>

<template>
    <AppLayout :title="imp.original_filename" :breadcrumbs="[{ label: 'Imports', href: route('imports.index') }]">
        <template #actions>
            <Button v-if="imp.failed_rows > 0" :href="route('imports.errors', { import: imp.id })" external>
                <Download />Download failed rows
            </Button>
            <Button v-if="!active && can('imports.create')" variant="primary" :href="route('imports.create', imp.source ? { source: imp.source.id } : {})"><FileUp />New import</Button>
        </template>

        <ImportSteps :current="3" class="mb-6" />

        <!-- Progress / outcome -->
        <section class="rounded-lg border border-border bg-surface px-5 py-4" aria-live="polite">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <StatusBadge :status="imp.status" :label="imp.status_label" />
                    <p class="text-sm text-secondary">
                        <template v-if="imp.status === 'pending'">Waiting for a worker to pick up the import…</template>
                        <template v-else-if="imp.status === 'processing'">
                            Processed <span class="num text-ink">{{ formatNumber(imp.processed_rows) }}</span> of
                            <span class="num text-ink">{{ formatNumber(imp.total_rows) }}</span> rows
                        </template>
                        <template v-else-if="imp.status === 'completed'">Every row was imported.</template>
                        <template v-else-if="imp.status === 'completed_with_errors'">Valid rows were imported; rejected rows are listed below.</template>
                        <template v-else>{{ imp.failure_reason }}</template>
                    </p>
                </div>
                <p class="label-instrument">{{ imp.dataset_label }} · {{ imp.source?.name ?? 'Direct upload' }}</p>
            </div>
            <div
                v-if="active"
                class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-sunken"
                role="progressbar"
                :aria-valuenow="imp.progress"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Import progress"
            >
                <div class="h-full bg-accent transition-[width] duration-500" :style="{ width: `${Math.max(imp.progress, 2)}%` }" />
            </div>
        </section>

        <!-- Counters -->
        <dl class="mt-4 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border md:grid-cols-4">
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Rows in file</dt>
                <dd class="num mt-1 text-kpi font-semibold text-ink">{{ formatNumber(imp.total_rows) }}</dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Imported</dt>
                <dd class="num mt-1 flex items-center gap-2 text-kpi font-semibold text-ink">
                    {{ formatNumber(imp.imported_rows) }}
                    <CircleCheck v-if="imp.status === 'completed'" class="size-4 text-positive" aria-hidden="true" />
                </dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Rejected</dt>
                <dd :class="['num mt-1 text-kpi font-semibold', imp.failed_rows ? 'text-negative' : 'text-ink']">{{ formatNumber(imp.failed_rows) }}</dd>
            </div>
            <div class="bg-surface px-4 py-3">
                <dt class="label-instrument">Of which duplicates</dt>
                <dd class="num mt-1 text-kpi font-semibold text-ink">{{ formatNumber(imp.duplicate_rows) }}</dd>
            </div>
        </dl>

        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_300px]">
            <!-- Rejected rows -->
            <section aria-labelledby="errors-heading" class="min-w-0">
                <div class="mb-2 flex items-baseline justify-between">
                    <h2 id="errors-heading" class="text-sm font-semibold text-ink">
                        Rejected rows <span class="num ml-1 font-normal text-secondary">{{ formatNumber(errors.meta.total) }}</span>
                    </h2>
                    <div v-if="Object.keys(errorSummary).length" class="hidden flex-wrap gap-x-3 text-xs text-secondary sm:flex">
                        <span v-for="(n, code) in errorSummary" :key="code">{{ codeLabels[code] ?? code }} <span class="num text-ink">{{ formatNumber(n) }}</span></span>
                    </div>
                </div>
                <DataTable :columns="errorColumns" :rows="errors.data" caption="Rejected rows">
                    <template #cell-code="{ row }">
                        <span class="inline-flex items-center gap-1.5 text-ink"><CircleAlert class="size-3.5 text-negative" aria-hidden="true" />{{ codeLabels[row.code] ?? row.code }}</span>
                    </template>
                    <template #cell-field="{ row }"><span class="font-mono text-xs text-secondary">{{ row.field ?? '—' }}</span></template>
                    <template #cell-message="{ row }"><span class="whitespace-normal text-secondary">{{ row.message }}</span></template>
                    <template #empty>
                        <p class="text-sm text-secondary">{{ active ? 'No rows rejected so far.' : 'No rows were rejected.' }}</p>
                    </template>
                </DataTable>
                <TablePagination :meta="errors.meta" :per-page-options="[25]" @page="errorsPage" />
            </section>

            <!-- Details -->
            <aside class="text-sm">
                <h2 class="border-b border-border pb-2 text-sm font-semibold text-ink">Details</h2>
                <dl class="divide-y divide-border">
                    <div class="flex justify-between gap-3 py-2"><dt class="text-secondary">Uploaded</dt><dd class="text-right text-ink">{{ fmt(imp.created_at) }}</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-secondary">By</dt><dd class="text-ink">{{ imp.created_by ?? '—' }}</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-secondary">Started</dt><dd class="text-right text-ink">{{ fmt(imp.started_at) }}</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-secondary">Completed</dt><dd class="text-right text-ink">{{ fmt(imp.completed_at) }}</dd></div>
                    <div v-if="duration" class="flex justify-between gap-3 py-2"><dt class="text-secondary">Duration</dt><dd class="num text-ink">{{ duration }}</dd></div>
                    <div class="flex justify-between gap-3 py-2"><dt class="text-secondary">File size</dt><dd class="num text-ink">{{ formatNumber(imp.size_bytes / 1024, 0) }} KB</dd></div>
                </dl>

                <h2 class="mt-6 border-b border-border pb-2 text-sm font-semibold text-ink">Column mapping</h2>
                <ul class="divide-y divide-border">
                    <li v-for="m in mappings" :key="m.target_field" class="flex justify-between gap-3 py-1.5 text-xs">
                        <span class="truncate font-mono text-ink" :title="m.source_column">{{ m.source_column }}</span>
                        <span class="shrink-0 text-secondary">→ {{ m.target_field }}</span>
                    </li>
                </ul>
                <Link v-if="imp.source" :href="route('sources.show', { source: imp.source.id })" class="mt-4 inline-block text-xs text-accent-ink hover:underline">View source: {{ imp.source.name }}</Link>
            </aside>
        </div>
    </AppLayout>
</template>
