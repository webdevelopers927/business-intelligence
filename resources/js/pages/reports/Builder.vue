<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { ArrowDown, ArrowUp, CalendarClock, CircleAlert, Download, LoaderCircle, Save, Trash2 } from 'lucide-vue-next';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import DateRangeMenu from '@/components/analytics/DateRangeMenu.vue';
import MultiSelectFilter from '@/components/analytics/MultiSelectFilter.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { useToasts } from '@/composables/useToasts';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { http, HttpError } from '@/lib/http';
import { formatMetric } from '@/lib/metrics';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/domain';
import {
    type AnalyticsOptions,
    type ExportFormat,
    FORMAT_OPTIONS,
    type ReportColumn,
    type ReportParameters,
    type ReportPreview,
    type ReportSchedule,
    type ReportTypeInfo,
} from '@/types/reports';

const props = defineProps<{
    types: ReportTypeInfo[];
    type: string;
    parameters: ReportParameters;
    report: { id: number; name: string } | null;
    schedule: ReportSchedule | null;
    scheduleDescription: string | null;
    nextRunAt: string | null;
    members: Option[];
    options: AnalyticsOptions;
    can: { save: boolean; saveAs: boolean; schedule: boolean; delete: boolean };
}>();

const { push } = useToasts();
const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const info = computed(() => props.types.find((t) => t.key === props.type) ?? props.types[0]!);

// Local working copy of the parameters; the server re-validates everything.
// (JSON copy: props are reactive proxies, which structuredClone rejects.)
const copy = (p: ReportParameters): ReportParameters => JSON.parse(JSON.stringify(p)) as ReportParameters;
const params = reactive<ReportParameters>(copy(props.parameters));
watch(() => props.parameters, (p) => Object.assign(params, copy(p)));

const typeOptions = computed<Option[]>(() => props.types.map((t) => ({ value: t.key, label: t.label })));
const sortOptions = computed<Option[]>(() => info.value.columns.map((c) => ({ value: c.key, label: c.label })));
const presetLabel = computed(() => props.options.presets.find((p) => p.value === params.range.preset)?.label ?? 'Custom range');

function changeType(key: string | number | null | undefined) {
    if (key && key !== props.type) router.visit(route('reports.create', { type: String(key) }));
}

function toggleColumn(key: string) {
    if (params.columns.includes(key)) {
        if (params.columns.length > 1) params.columns = params.columns.filter((c) => c !== key);
    } else {
        // Keep the catalog's column order regardless of click order.
        params.columns = info.value.columns.map((c) => c.key).filter((c) => c === key || params.columns.includes(c));
    }
}

function sortBy(key: string) {
    params.sort = params.sort.column === key ? { column: key, direction: params.sort.direction === 'asc' ? 'desc' : 'asc' } : { column: key, direction: 'desc' };
}

// --- Preview -------------------------------------------------------------
const preview = ref<ReportPreview | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let sequence = 0;

async function load(page = 1) {
    const ticket = ++sequence;
    loading.value = true;
    error.value = null;
    try {
        const res = await http.post<{ data: ReportPreview }>(route('reports.preview'), { type: props.type, parameters: params, page });
        if (ticket === sequence) preview.value = res.data;
    } catch (e) {
        if (ticket === sequence) error.value = e instanceof HttpError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'The preview could not be loaded.';
    } finally {
        if (ticket === sequence) loading.value = false;
    }
}
const reload = useDebounceFn(() => load(1), 250);
watch(params, reload, { deep: true });
onMounted(() => load(1));

function cell(col: ReportColumn, value: string | number | null): string {
    if (value === null || value === '') return '—';
    switch (col.type) {
        case 'currency':
            return formatMetric(Number(value), 'currency', currency.value);
        case 'percent':
            return formatMetric(Number(value), 'percent', currency.value);
        case 'number':
            return formatMetric(Number(value), 'number', currency.value);
        case 'date':
            return formatDate(String(value));
        case 'datetime':
            return formatDate(String(value).replace(' ', 'T'), { dateStyle: 'medium', timeStyle: 'short' });
        default:
            return String(value);
    }
}
const numeric = (c: ReportColumn) => ['number', 'currency', 'percent'].includes(c.type);

// --- Export --------------------------------------------------------------
const format = ref<ExportFormat>('xlsx');
const exporting = ref(false);

async function exportFile() {
    exporting.value = true;
    try {
        const res = await http.post<{ data: { status: string; download_url: string | null; message: string | null } }>(route('reports.export'), {
            type: props.type,
            parameters: params,
            format: format.value,
            report_id: props.report?.id ?? null,
        });
        if (res.data.download_url) {
            window.location.href = res.data.download_url;
        } else {
            push({ type: 'info', message: res.data.message ?? 'Your export is being prepared.' });
        }
    } catch (e) {
        push({ type: 'error', message: e instanceof HttpError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : 'The export failed.' });
    } finally {
        exporting.value = false;
    }
}

// --- Save ----------------------------------------------------------------
const saveOpen = ref(false);
const saveAsNew = ref(false);
const saveForm = useForm({ name: '' });

function openSave(asNew: boolean) {
    saveAsNew.value = asNew;
    saveForm.name = asNew || !props.report ? (props.report ? `${props.report.name} (copy)` : info.value.label) : props.report.name;
    saveForm.clearErrors();
    saveOpen.value = true;
}

function submitSave() {
    const payload = { name: saveForm.name, type: props.type, parameters: { ...params } };
    const opts = { preserveScroll: true, onSuccess: () => (saveOpen.value = false) };
    if (props.report && !saveAsNew.value) {
        saveForm.transform(() => payload).patch(route('reports.update', { report: props.report.id }), opts);
    } else {
        saveForm.transform(() => payload).post(route('reports.store'), opts);
    }
}

function quickSave() {
    if (!props.report) return openSave(false);
    router.patch(route('reports.update', { report: props.report.id }), { name: props.report.name, parameters: { ...params } }, { preserveScroll: true });
}

// --- Schedule ------------------------------------------------------------
const scheduleOpen = ref(false);
const scheduleForm = useForm({
    frequency: props.schedule?.frequency ?? 'weekly',
    day_of_week: props.schedule?.day_of_week ?? 1,
    day_of_month: props.schedule?.day_of_month ?? 1,
    run_at: props.schedule?.run_at ?? '08:00',
    format: props.schedule?.format ?? ('pdf' as ExportFormat),
    recipients: (props.schedule?.recipients ?? []).map(String),
    is_active: props.schedule?.is_active ?? true,
});
const weekdays: Option[] = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'].map((d, i) => ({ value: String(i + 1), label: d }));
const frequencies: Option[] = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];
const monthDays: Option[] = Array.from({ length: 31 }, (_, i) => ({ value: String(i + 1), label: i + 1 > 28 ? `${i + 1} (or last day)` : String(i + 1) }));

function toggleRecipient(id: string) {
    scheduleForm.recipients = scheduleForm.recipients.includes(id) ? scheduleForm.recipients.filter((r) => r !== id) : [...scheduleForm.recipients, id];
}

function submitSchedule() {
    if (!props.report) return;
    scheduleForm
        .transform((d) => ({
            ...d,
            day_of_week: d.frequency === 'weekly' ? Number(d.day_of_week) : null,
            day_of_month: d.frequency === 'monthly' ? Number(d.day_of_month) : null,
            recipients: d.recipients.map(Number),
        }))
        .put(route('reports.schedule', { report: props.report.id }), { preserveScroll: true, onSuccess: () => (scheduleOpen.value = false) });
}

function removeSchedule() {
    if (!props.report) return;
    router.delete(route('reports.unschedule', { report: props.report.id }), { preserveScroll: true, onSuccess: () => (scheduleOpen.value = false) });
}

const deleting = ref(false);
</script>

<template>
    <AppLayout :title="report?.name ?? 'New report'" :breadcrumbs="[{ label: 'Reports', href: route('reports.index') }]">
        <template #actions>
            <div class="flex flex-wrap items-center gap-2">
                <NativeSelect v-model="format" :options="FORMAT_OPTIONS" aria-label="Export format" class="w-36" />
                <Button variant="primary" :loading="exporting" :disabled="!preview || preview.meta.total === 0" @click="exportFile"><Download />Export</Button>
                <Button v-if="can.save" @click="quickSave"><Save />Save</Button>
                <Button v-if="report && can.saveAs" variant="ghost" @click="openSave(true)">Save as…</Button>
                <Button v-if="can.schedule" variant="ghost" @click="scheduleOpen = true"><CalendarClock />{{ schedule ? 'Schedule' : 'Schedule…' }}</Button>
                <Button v-if="can.delete" variant="ghost" size="icon" aria-label="Delete report" @click="deleting = true"><Trash2 /></Button>
            </div>
        </template>

        <p v-if="scheduleDescription" class="mb-4 inline-flex items-center gap-1.5 rounded-md bg-accent-subtle px-2.5 py-1 text-xs text-accent-ink">
            <CalendarClock class="size-3.5" aria-hidden="true" />
            {{ schedule?.is_active ? scheduleDescription : 'Schedule paused' }}
            <template v-if="schedule?.is_active && nextRunAt"> · next {{ formatDate(nextRunAt, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</template>
        </p>

        <div class="grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">
            <!-- Configuration -->
            <aside class="flex flex-col gap-5" aria-label="Report settings">
                <FormField id="report-type" label="Report">
                    <NativeSelect id="report-type" :model-value="type" :options="typeOptions" :disabled="report !== null" @update:model-value="changeType" />
                </FormField>
                <p class="-mt-3 text-xs text-secondary">{{ info.description }}</p>

                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-ink">Period and filters</p>
                    <div class="flex flex-wrap gap-2">
                        <DateRangeMenu
                            :presets="options.presets"
                            :range="params.range.preset"
                            :from="params.range.from ?? ''"
                            :to="params.range.to ?? ''"
                            :label="params.range.preset === 'custom' ? (preview?.period ?? presetLabel) : presetLabel"
                            @change="(p) => (params.range = { preset: p.range, from: p.from ?? null, to: p.to ?? null })"
                        />
                        <MultiSelectFilter v-if="info.filters.includes('branches')" v-model="params.filters.branches" label="Branches" all-label="All branches" :options="options.branches" />
                        <MultiSelectFilter v-if="info.filters.includes('categories')" v-model="params.filters.categories" label="Categories" all-label="All categories" :options="options.categories" />
                    </div>
                </div>

                <fieldset class="flex flex-col gap-1">
                    <legend class="mb-1 text-sm font-medium text-ink">Columns</legend>
                    <label v-for="c in info.columns" :key="c.key" class="flex h-7 cursor-pointer items-center gap-2 text-sm text-ink">
                        <input
                            type="checkbox"
                            class="size-4 accent-[var(--color-accent)]"
                            :checked="params.columns.includes(c.key)"
                            :disabled="params.columns.length === 1 && params.columns.includes(c.key)"
                            @change="toggleColumn(c.key)"
                        />
                        {{ c.label }}
                    </label>
                </fieldset>

                <FormField id="report-sort" label="Sort by">
                    <div class="flex gap-2">
                        <NativeSelect id="report-sort" v-model="params.sort.column" :options="sortOptions" class="min-w-0 flex-1" />
                        <Button
                            size="icon"
                            :aria-label="params.sort.direction === 'asc' ? 'Ascending — switch to descending' : 'Descending — switch to ascending'"
                            @click="params.sort.direction = params.sort.direction === 'asc' ? 'desc' : 'asc'"
                        >
                            <ArrowUp v-if="params.sort.direction === 'asc'" /><ArrowDown v-else />
                        </Button>
                    </div>
                </FormField>
            </aside>

            <!-- Preview -->
            <section aria-labelledby="preview-heading" class="min-w-0">
                <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="preview-heading" class="text-sm font-semibold text-ink">
                        Preview
                        <span v-if="preview" class="num ml-1 font-normal text-secondary">{{ preview.meta.total.toLocaleString() }} rows · {{ preview.period }}</span>
                    </h2>
                    <LoaderCircle v-if="loading" class="size-4 animate-spin text-muted" aria-label="Loading preview" />
                </div>

                <div v-if="error" class="flex items-start gap-2 rounded-lg border border-negative/40 bg-negative-subtle p-4 text-sm text-negative" role="alert">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ error }}
                </div>

                <div v-else :class="cn('overflow-x-auto rounded-lg border border-border bg-surface transition-opacity', loading && preview && 'opacity-60')">
                    <table class="w-full text-sm">
                        <caption class="sr-only">{{ info.label }} preview</caption>
                        <thead class="bg-surface-sunken text-xs text-secondary">
                            <tr class="h-8">
                                <th
                                    v-for="c in preview?.columns ?? []"
                                    :key="c.key"
                                    scope="col"
                                    :aria-sort="params.sort.column === c.key ? (params.sort.direction === 'asc' ? 'ascending' : 'descending') : undefined"
                                    :class="cn('px-3 font-medium whitespace-nowrap first:pl-4 last:pr-4', numeric(c) ? 'text-right' : 'text-left')"
                                >
                                    <button type="button" class="inline-flex items-center gap-1 hover:text-ink" @click="sortBy(c.key)">
                                        {{ c.label }}
                                        <ArrowUp v-if="params.sort.column === c.key && params.sort.direction === 'asc'" class="size-3" aria-hidden="true" />
                                        <ArrowDown v-else-if="params.sort.column === c.key" class="size-3" aria-hidden="true" />
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(row, i) in preview?.rows ?? []" :key="i" class="h-9">
                                <td
                                    v-for="c in preview!.columns"
                                    :key="c.key"
                                    :class="cn('px-3 whitespace-nowrap first:pl-4 last:pr-4', numeric(c) ? 'num text-right' : 'text-ink', c.type === 'date' || c.type === 'datetime' ? 'text-secondary' : '')"
                                >
                                    {{ cell(c, row[c.key] ?? null) }}
                                </td>
                            </tr>
                            <tr v-if="preview && preview.rows.length === 0">
                                <td :colspan="preview.columns.length" class="px-4 py-10 text-center text-sm text-secondary">No data for this period and filters. Try a wider date range.</td>
                            </tr>
                            <tr v-if="!preview && loading">
                                <td class="px-4 py-10 text-center text-sm text-secondary">Loading preview…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="preview?.note" class="mt-2 text-xs text-secondary">{{ preview.note }}</p>
                <TablePagination v-if="preview" :meta="preview.meta" :per-page-options="[]" @page="(p: number) => load(p)" />
            </section>
        </div>

        <!-- Save dialog -->
        <Dialog v-model:open="saveOpen" :title="saveAsNew ? 'Save as a new report' : 'Save report'" description="Saved reports keep the period, filters, columns and sort.">
            <form class="flex flex-col gap-4" @submit.prevent="submitSave">
                <FormField id="save-name" label="Name" :error="saveForm.errors.name" required>
                    <template #default="{ id, invalid, describedBy }">
                        <Input :id="id" v-model="saveForm.name" :invalid="invalid" :aria-describedby="describedBy" maxlength="120" autofocus />
                    </template>
                </FormField>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="saveOpen = false">Cancel</Button>
                    <Button type="submit" variant="primary" :loading="saveForm.processing">Save</Button>
                </div>
            </form>
        </Dialog>

        <!-- Schedule dialog -->
        <Dialog v-if="report" v-model:open="scheduleOpen" title="Schedule delivery" description="Recipients get an in-app notification and an email with a download link." size="md">
            <form class="flex flex-col gap-4" @submit.prevent="submitSchedule">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField id="sch-frequency" label="Frequency" :error="scheduleForm.errors.frequency">
                        <NativeSelect id="sch-frequency" v-model="scheduleForm.frequency" :options="frequencies" />
                    </FormField>
                    <FormField v-if="scheduleForm.frequency === 'weekly'" id="sch-dow" label="Day" :error="scheduleForm.errors.day_of_week">
                        <NativeSelect id="sch-dow" :model-value="String(scheduleForm.day_of_week)" :options="weekdays" @update:model-value="(v) => (scheduleForm.day_of_week = Number(v))" />
                    </FormField>
                    <FormField v-if="scheduleForm.frequency === 'monthly'" id="sch-dom" label="Day of month" :error="scheduleForm.errors.day_of_month">
                        <NativeSelect id="sch-dom" :model-value="String(scheduleForm.day_of_month)" :options="monthDays" @update:model-value="(v) => (scheduleForm.day_of_month = Number(v))" />
                    </FormField>
                    <FormField id="sch-time" label="Time" :error="scheduleForm.errors.run_at" hint="In the organization's time zone.">
                        <template #default="{ id, invalid, describedBy }">
                            <Input :id="id" v-model="scheduleForm.run_at" type="time" step="300" :invalid="invalid" :aria-describedby="describedBy" />
                        </template>
                    </FormField>
                    <FormField id="sch-format" label="Format" :error="scheduleForm.errors.format">
                        <NativeSelect id="sch-format" v-model="scheduleForm.format" :options="FORMAT_OPTIONS" />
                    </FormField>
                </div>

                <fieldset>
                    <legend class="mb-1.5 text-sm font-medium text-ink">Recipients<span class="ml-0.5 text-negative" aria-hidden="true">*</span></legend>
                    <div class="max-h-44 overflow-y-auto rounded-md border border-border p-1">
                        <label v-for="m in members" :key="m.value" class="flex h-8 cursor-pointer items-center gap-2 rounded-sm px-2 text-sm text-ink hover:bg-surface-hover">
                            <input type="checkbox" class="size-4 accent-[var(--color-accent)]" :checked="scheduleForm.recipients.includes(m.value)" @change="toggleRecipient(m.value)" />
                            {{ m.label }}
                        </label>
                    </div>
                    <p v-if="scheduleForm.errors.recipients" class="mt-1 text-xs text-negative" role="alert">{{ scheduleForm.errors.recipients }}</p>
                </fieldset>

                <label class="flex items-center gap-2 text-sm text-ink">
                    <input v-model="scheduleForm.is_active" type="checkbox" class="size-4 accent-[var(--color-accent)]" />
                    Active
                </label>

                <p class="text-xs text-secondary">Each run uses the saved settings, with the period recalculated on the run date (e.g. “Last month”). Save the report first if you changed it.</p>

                <div class="flex flex-wrap justify-between gap-2">
                    <Button v-if="schedule" variant="ghost" class="text-negative" @click="removeSchedule">Remove schedule</Button>
                    <span v-else />
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="scheduleOpen = false">Cancel</Button>
                        <Button type="submit" variant="primary" :loading="scheduleForm.processing">Save schedule</Button>
                    </div>
                </div>
            </form>
        </Dialog>

        <ConfirmDialog
            v-if="report"
            :open="deleting"
            :title="`Delete ${report.name}?`"
            description="Its schedule stops. Files already exported stay available in recent exports."
            confirm-label="Delete report"
            @update:open="(o: boolean) => (deleting = o)"
            @confirm="router.delete(route('reports.destroy', { report: report.id }))"
        />
    </AppLayout>
</template>
