<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import ImportSteps from '@/components/imports/ImportSteps.vue';
import Button from '@/components/ui/Button.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { ImportField, ImportSummary } from '@/types/data';
import type { Option } from '@/types/domain';

const props = defineProps<{
    import: ImportSummary;
    headers: string[];
    fields: ImportField[];
    mapping: Record<string, string | null>;
    dateFormat: string;
    sample: Record<string, string | null>[];
    dateFormats: Option[];
}>();

const imp = computed(() => props.import);

const form = useForm({
    mapping: Object.fromEntries(props.fields.map((f) => [f.key, props.mapping[f.key] ?? ''])) as Record<string, string>,
    date_format: props.dateFormat,
});

const columnOptions = computed<Option[]>(() => [{ value: '', label: '— Not mapped —' }, ...props.headers.map((h) => ({ value: h, label: h }))]);
const hasDateField = computed(() => props.fields.some((f) => f.type === 'date' || f.type === 'datetime'));
const missingRequired = computed(() => props.fields.filter((f) => f.required && !form.mapping[f.key]));
const mappedColumns = computed(() => new Set(Object.values(form.mapping).filter(Boolean)));
const reused = computed(() => {
    const counts = new Map<string, number>();
    Object.values(form.mapping).forEach((c) => c && counts.set(c, (counts.get(c) ?? 0) + 1));
    return new Set([...counts].filter(([, n]) => n > 1).map(([c]) => c));
});

const firstValue = (column: string | undefined) => {
    if (!column) return null;
    const row = props.sample.find((r) => r[column]);
    return row?.[column] ?? null;
};

const fieldError = (key: string) => (form.errors as Record<string, string | undefined>)[`mapping.${key}`];

function submit() {
    const mapping = Object.fromEntries(Object.entries(form.mapping).map(([k, v]) => [k, v || null]));
    form.transform(() => ({ mapping, date_format: form.date_format })).post(route('imports.confirm', { import: props.import.id }));
}
</script>

<template>
    <AppLayout title="Map columns" :breadcrumbs="[{ label: 'Imports', href: route('imports.index') }, { label: imp.original_filename }]">
        <ImportSteps :current="2" class="mb-6" />

        <div class="grid gap-6 xl:grid-cols-[400px_1fr]">
            <!-- Mapping rail -->
            <form class="flex flex-col" novalidate @submit.prevent="submit">
                <div class="mb-3">
                    <p class="label-instrument">{{ imp.dataset_label }}</p>
                    <h2 class="mt-1 text-sm font-semibold text-ink">Match each field to a column in your file</h2>
                    <p class="mt-1 text-sm text-secondary">Suggestions are based on column names. Required fields are marked <span class="text-negative">*</span>.</p>
                </div>

                <ul class="divide-y divide-border rounded-lg border border-border bg-surface">
                    <li v-for="field in fields" :key="field.key" class="px-3.5 py-2.5">
                        <div class="grid grid-cols-[1fr_180px] items-center gap-3">
                            <label :for="`map-${field.key}`" class="min-w-0">
                                <span class="block truncate text-sm font-medium text-ink">
                                    {{ field.label }}<span v-if="field.required" class="ml-0.5 text-negative" aria-hidden="true">*</span>
                                </span>
                                <span class="block truncate text-xs text-secondary" :title="firstValue(form.mapping[field.key]) ?? undefined">
                                    <template v-if="form.mapping[field.key]">e.g. <span class="font-mono">{{ firstValue(form.mapping[field.key]) ?? 'empty in preview' }}</span></template>
                                    <template v-else>{{ field.hint ?? (field.required ? 'Required' : 'Optional') }}</template>
                                </span>
                            </label>
                            <NativeSelect
                                :id="`map-${field.key}`"
                                v-model="form.mapping[field.key]"
                                :options="columnOptions"
                                :invalid="Boolean(fieldError(field.key))"
                                :aria-required="field.required"
                            />
                        </div>
                        <p v-if="fieldError(field.key)" class="mt-1.5 flex items-center gap-1 text-xs text-negative" role="alert">
                            <CircleAlert class="size-3.5" aria-hidden="true" />{{ fieldError(field.key) }}
                        </p>
                        <p v-else-if="form.mapping[field.key] && reused.has(form.mapping[field.key]!)" class="mt-1.5 text-xs text-warning">
                            This column is also used by another field.
                        </p>
                    </li>
                </ul>

                <div v-if="hasDateField" class="mt-4 grid grid-cols-[1fr_180px] items-center gap-3 rounded-lg border border-border bg-surface px-3.5 py-2.5">
                    <label for="date-format">
                        <span class="block text-sm font-medium text-ink">Date format</span>
                        <span class="block text-xs text-secondary">Detected from your file. Times are read in your organization's timezone.</span>
                    </label>
                    <NativeSelect id="date-format" v-model="form.date_format" :options="dateFormats.map((d) => ({ value: d.value, label: `${d.label}` }))" class="font-mono" />
                </div>

                <div class="mt-5 flex items-center justify-between gap-3">
                    <p class="text-xs text-secondary">
                        <template v-if="missingRequired.length">{{ missingRequired.length }} required field(s) not mapped</template>
                        <template v-else>All required fields mapped</template>
                    </p>
                    <Button type="submit" variant="primary" :disabled="missingRequired.length > 0" :loading="form.processing">Start import</Button>
                </div>
            </form>

            <!-- Preview -->
            <section class="min-w-0" aria-labelledby="preview-heading">
                <div class="mb-3 flex items-baseline justify-between">
                    <h2 id="preview-heading" class="text-sm font-semibold text-ink">File preview</h2>
                    <p class="text-xs text-secondary">First {{ sample.length }} rows · {{ headers.length }} columns · mapped columns highlighted</p>
                </div>
                <div class="overflow-auto rounded-lg border border-border bg-surface xl:max-h-[calc(100dvh-260px)]">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-surface-sunken">
                            <tr class="h-8 text-left text-secondary">
                                <th scope="col" class="num w-10 px-3 text-right font-medium">#</th>
                                <th
                                    v-for="h in headers"
                                    :key="h"
                                    scope="col"
                                    :class="cn('px-3 font-medium whitespace-nowrap', mappedColumns.has(h) && 'bg-accent-subtle text-accent-ink')"
                                >
                                    {{ h }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(row, i) in sample" :key="i" class="h-8">
                                <td class="num px-3 text-right text-muted">{{ i + 2 }}</td>
                                <td
                                    v-for="h in headers"
                                    :key="h"
                                    :class="cn('max-w-56 truncate px-3 font-mono text-ink', mappedColumns.has(h) ? 'bg-accent-subtle/40' : 'text-secondary')"
                                    :title="row[h] ?? undefined"
                                >
                                    {{ row[h] ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-secondary">Row numbers match your spreadsheet (row 1 is the header), so error reports point to the right line.</p>
            </section>
        </div>
    </AppLayout>
</template>
