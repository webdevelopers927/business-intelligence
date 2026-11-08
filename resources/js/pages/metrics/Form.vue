<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { CircleAlert, LoaderCircle } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import SettingsSection from '@/components/ui/SettingsSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { http, HttpError } from '@/lib/http';
import { formatMetric, type MetricFormat } from '@/lib/metrics';
import { route } from '@/lib/route';

interface Reference {
    key: string;
    label: string;
    format: MetricFormat;
    custom: boolean;
}

const props = defineProps<{
    metric: { id: number; key: string; name: string; description: string | null; formula: string; format: MetricFormat; decimals: number; polarity: string; visibility: string } | null;
    references: Reference[];
}>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const form = useForm({
    name: props.metric?.name ?? '',
    description: props.metric?.description ?? '',
    formula: props.metric?.formula ?? '',
    format: (props.metric?.format ?? 'number') as MetricFormat,
    decimals: props.metric?.decimals ?? 1,
    polarity: props.metric?.polarity ?? 'up_good',
    visibility: props.metric?.visibility ?? 'organization',
});

// ── Live validation + preview ──────────────────────────────────────────
const preview = ref<{ value: number | null; inputs: Record<string, number | null>; range: string } | null>(null);
const previewError = ref('');
const checking = ref(false);
const textarea = ref<HTMLTextAreaElement | null>(null);

const runPreview = useDebounceFn(async () => {
    if (!form.formula.trim()) {
        preview.value = null;
        previewError.value = '';
        return;
    }
    checking.value = true;
    try {
        const res = await http.post<{ data: NonNullable<typeof preview.value> }>(route('metrics.preview'), { formula: form.formula, key: props.metric?.key ?? null });
        preview.value = res.data;
        previewError.value = '';
    } catch (e) {
        preview.value = null;
        previewError.value = e instanceof HttpError ? e.message : 'The formula could not be checked.';
    } finally {
        checking.value = false;
    }
}, 400);

watch(() => form.formula, runPreview);
onMounted(() => form.formula && runPreview());

function insert(key: string) {
    const el = textarea.value;
    const start = el?.selectionStart ?? form.formula.length;
    const end = el?.selectionEnd ?? form.formula.length;
    const before = form.formula.slice(0, start);
    const needsSpace = before.length > 0 && !/[\s(]$/.test(before);
    form.formula = `${before}${needsSpace ? ' ' : ''}${key}${form.formula.slice(end)}`;
    requestAnimationFrame(() => {
        el?.focus();
        const pos = before.length + key.length + (needsSpace ? 1 : 0);
        el?.setSelectionRange(pos, pos);
    });
}

const labelFor = (key: string) => props.references.find((r) => r.key === key)?.label ?? key;
const formatFor = (key: string) => props.references.find((r) => r.key === key)?.format ?? 'number';

function submit() {
    if (props.metric) {
        form.patch(route('metrics.update', { metric: props.metric.id }));
    } else {
        form.post(route('metrics.store'));
    }
}
</script>

<template>
    <AppLayout :title="metric ? `Edit ${metric.name}` : 'New metric'" :breadcrumbs="[{ label: 'Metrics', href: route('metrics.index') }]" width="settings">
        <form novalidate @submit.prevent="submit">
            <SettingsSection title="Formula" description="Combine metrics with + − × ÷ and parentheses. Results are recalculated live from your data; division by zero shows as —.">
                <div class="flex flex-col gap-3">
                    <FormField id="formula" label="Formula" :error="form.errors.formula" required>
                        <template #default="{ id, invalid }">
                            <textarea
                                :id="id"
                                ref="textarea"
                                v-model="form.formula"
                                rows="3"
                                spellcheck="false"
                                maxlength="300"
                                placeholder="net_profit / orders"
                                :aria-invalid="invalid || Boolean(previewError) || undefined"
                                class="w-full rounded-md border border-border-strong bg-surface px-2.5 py-2 font-mono text-sm text-ink placeholder:text-muted focus-visible:border-accent aria-invalid:border-negative"
                            />
                        </template>
                    </FormField>

                    <div class="rounded-md border border-border bg-surface-sunken px-3 py-2.5 text-sm" aria-live="polite">
                        <p v-if="checking" class="flex items-center gap-2 text-secondary"><LoaderCircle class="size-3.5 animate-spin" />Checking…</p>
                        <p v-else-if="previewError" class="flex items-start gap-2 text-negative"><CircleAlert class="mt-0.5 size-4 shrink-0" />{{ previewError }}</p>
                        <template v-else-if="preview">
                            <p class="label-instrument">Preview · {{ preview.range }}</p>
                            <p class="num mt-1 text-lg font-semibold text-ink">{{ formatMetric(preview.value, form.format, currency) }}</p>
                            <p class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-secondary">
                                <span v-for="(v, k) in preview.inputs" :key="k">{{ labelFor(String(k)) }} <span class="num text-ink">{{ formatMetric(v, formatFor(String(k)), currency, true) }}</span></span>
                            </p>
                        </template>
                        <p v-else class="text-secondary">Start typing to see the value for the last 30 days.</p>
                    </div>

                    <div>
                        <p class="mb-1.5 text-xs text-secondary">Insert a metric</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="r in references"
                                :key="r.key"
                                type="button"
                                class="inline-flex h-6 items-center rounded-sm border border-border-strong bg-surface px-1.5 font-mono text-[11px] text-secondary hover:border-accent hover:text-accent-ink"
                                :title="r.label"
                                @click="insert(r.key)"
                            >
                                {{ r.key }}
                            </button>
                        </div>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection title="Details" description="How the metric is named and displayed.">
                <div class="grid max-w-[480px] gap-4">
                    <FormField id="name" label="Name" :error="form.errors.name" :hint="metric ? `Key: ${metric.key}` : 'A key for formulas is generated from the name.'" required>
                        <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.name" maxlength="80" :invalid="invalid" :aria-describedby="describedBy" /></template>
                    </FormField>
                    <FormField id="description" label="Description" :error="form.errors.description">
                        <template #default="{ id, invalid }"><Input :id="id" v-model="form.description" maxlength="255" :invalid="invalid" /></template>
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField id="format" label="Format" :error="form.errors.format" required>
                            <template #default="{ id }"><NativeSelect :id="id" v-model="form.format" :options="[{ value: 'currency', label: 'Currency' }, { value: 'number', label: 'Number' }, { value: 'percent', label: 'Percent' }]" /></template>
                        </FormField>
                        <FormField id="decimals" label="Decimals" :error="form.errors.decimals" required>
                            <template #default="{ id }"><NativeSelect :id="id" v-model.number="form.decimals" :options="[0, 1, 2, 3, 4].map((n) => ({ value: String(n), label: String(n) }))" /></template>
                        </FormField>
                        <FormField id="polarity" label="Higher is" :error="form.errors.polarity" required>
                            <template #default="{ id }"><NativeSelect :id="id" v-model="form.polarity" :options="[{ value: 'up_good', label: 'Better' }, { value: 'up_bad', label: 'Worse' }, { value: 'neutral', label: 'Neutral' }]" /></template>
                        </FormField>
                    </div>
                    <FormField id="visibility" label="Visibility" :error="form.errors.visibility" required>
                        <template #default="{ id }"><NativeSelect :id="id" v-model="form.visibility" :options="[{ value: 'organization', label: 'Everyone in the organization' }, { value: 'private', label: 'Only me' }]" /></template>
                    </FormField>
                </div>
            </SettingsSection>

            <div class="flex justify-end gap-2 border-t border-border pt-4">
                <Button :href="route('metrics.index')">Cancel</Button>
                <Button type="submit" variant="primary" :loading="form.processing">{{ metric ? 'Save metric' : 'Create metric' }}</Button>
            </div>
        </form>
    </AppLayout>
</template>
