<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MultiSelectFilter from '@/components/analytics/MultiSelectFilter.vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import SettingsSection from '@/components/ui/SettingsSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { MetricFormat } from '@/lib/metrics';
import { route } from '@/lib/route';
import type { AlertKind } from '@/types/alerts';
import type { Option } from '@/types/domain';
import type { AnalyticsOptions } from '@/types/reports';

interface AlertInput {
    id: number;
    name: string;
    kind: AlertKind;
    metric_key: string | null;
    condition: string | null;
    threshold: string | number | null;
    window: string | null;
    filters: { branches?: number[] } | null;
    frequency: string;
    recipients: number[];
    is_active: boolean;
}

const props = defineProps<{
    alert: AlertInput | null;
    metrics: (Option & { format: MetricFormat })[];
    members: Option[];
    options: AnalyticsOptions;
}>();

const form = useForm({
    name: props.alert?.name ?? '',
    kind: props.alert?.kind ?? ('metric' as AlertKind),
    metric_key: props.alert?.metric_key ?? 'revenue',
    condition: props.alert?.condition ?? 'drops_by_pct',
    threshold: props.alert?.threshold === null || props.alert?.threshold === undefined ? '' : String(Number(props.alert.threshold)),
    window: props.alert?.window ?? 'week',
    branches: props.alert?.filters?.branches ?? ([] as number[]),
    frequency: props.alert?.frequency ?? 'daily',
    recipients: (props.alert?.recipients ?? []).map(String),
    is_active: props.alert?.is_active ?? true,
});

const kinds: Option[] = [
    { value: 'metric', label: 'Metric threshold', description: 'A KPI crosses a value or changes sharply.' },
    { value: 'import_failed', label: 'Import failure', description: 'Any import fails.' },
    { value: 'source_stale', label: 'Stale data source', description: 'An active source stops synchronizing.' },
];
const conditions: Option[] = [
    { value: 'below', label: 'Falls below' },
    { value: 'above', label: 'Rises above' },
    { value: 'drops_by_pct', label: 'Drops by more than (%)' },
    { value: 'rises_by_pct', label: 'Rises by more than (%)' },
];
const windows: Option[] = [
    { value: 'day', label: 'Yesterday' },
    { value: 'week', label: 'Last 7 days' },
    { value: 'month', label: 'Last 30 days' },
];
const frequencies: Option[] = [
    { value: 'hourly', label: 'Every hour' },
    { value: 'daily', label: 'Every day' },
    { value: 'weekly', label: 'Every week' },
];

const isPct = computed(() => form.condition === 'drops_by_pct' || form.condition === 'rises_by_pct');
const metricFormat = computed(() => props.metrics.find((m) => m.value === form.metric_key)?.format ?? 'number');
const thresholdHint = computed(() => {
    if (form.kind === 'source_stale') return 'Hours without a successful synchronization (1–720).';
    if (isPct.value) return 'Compared with the preceding period of the same length.';
    return metricFormat.value === 'percent' ? 'In percent, e.g. 25 for 25%.' : metricFormat.value === 'currency' ? 'In the organization currency.' : '';
});

function toggleRecipient(id: string) {
    form.recipients = form.recipients.includes(id) ? form.recipients.filter((r) => r !== id) : [...form.recipients, id];
}

function submit() {
    const t = form.transform((d) => ({ ...d, recipients: d.recipients.map(Number), threshold: d.threshold === '' ? null : Number(d.threshold) }));
    if (props.alert) t.patch(route('alerts.update', { alert: props.alert.id }));
    else t.post(route('alerts.store'));
}
</script>

<template>
    <AppLayout :title="alert ? `Edit ${alert.name}` : 'New alert'" :breadcrumbs="[{ label: 'Alerts', href: route('alerts.index') }]" width="settings">
        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <SettingsSection title="Alert" description="What to watch.">
                <div class="flex flex-col gap-4">
                    <FormField id="alert-name" label="Name" :error="form.errors.name" required>
                        <template #default="{ id, invalid, describedBy }">
                            <Input :id="id" v-model="form.name" :invalid="invalid" :aria-describedby="describedBy" maxlength="120" placeholder="Weekly revenue drop" />
                        </template>
                    </FormField>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-medium text-ink">Type</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            <label
                                v-for="k in kinds"
                                :key="k.value"
                                :class="[
                                    'flex cursor-pointer flex-col gap-0.5 rounded-md border p-3 text-sm transition-colors',
                                    form.kind === k.value ? 'border-accent bg-accent-subtle' : 'border-border hover:bg-surface-hover',
                                ]"
                            >
                                <span class="flex items-center gap-2 font-medium text-ink"><input v-model="form.kind" type="radio" :value="k.value" class="accent-[var(--color-accent)]" />{{ k.label }}</span>
                                <span class="text-xs text-secondary">{{ k.description }}</span>
                            </label>
                        </div>
                    </fieldset>

                    <template v-if="form.kind === 'metric'">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField id="alert-metric" label="Metric" :error="form.errors.metric_key">
                                <NativeSelect id="alert-metric" v-model="form.metric_key" :options="metrics" />
                            </FormField>
                            <FormField id="alert-window" label="Measured over" :error="form.errors.window">
                                <NativeSelect id="alert-window" v-model="form.window" :options="windows" />
                            </FormField>
                            <FormField id="alert-condition" label="Condition" :error="form.errors.condition">
                                <NativeSelect id="alert-condition" v-model="form.condition" :options="conditions" />
                            </FormField>
                            <FormField id="alert-threshold" :label="isPct ? 'Change (%)' : 'Threshold'" :error="form.errors.threshold" :hint="thresholdHint" required>
                                <template #default="{ id, invalid, describedBy }">
                                    <Input :id="id" v-model="form.threshold" type="number" step="any" :invalid="invalid" :aria-describedby="describedBy" />
                                </template>
                            </FormField>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <p class="text-sm font-medium text-ink">Branches</p>
                            <div><MultiSelectFilter v-model="form.branches" label="Branches" all-label="All branches" :options="options.branches" /></div>
                        </div>
                    </template>

                    <FormField v-else-if="form.kind === 'source_stale'" id="alert-hours" label="Hours" :error="form.errors.threshold" :hint="thresholdHint" required>
                        <template #default="{ id, invalid, describedBy }">
                            <Input :id="id" v-model="form.threshold" type="number" min="1" max="720" :invalid="invalid" :aria-describedby="describedBy" class="w-32" />
                        </template>
                    </FormField>
                </div>
            </SettingsSection>

            <SettingsSection title="Delivery" description="Who is notified, and how often the condition is checked.">
                <div class="flex flex-col gap-4">
                    <FormField id="alert-frequency" label="Check" :error="form.errors.frequency">
                        <NativeSelect id="alert-frequency" v-model="form.frequency" :options="frequencies" class="sm:w-60" />
                    </FormField>
                    <fieldset>
                        <legend class="mb-1.5 text-sm font-medium text-ink">Recipients<span class="ml-0.5 text-negative" aria-hidden="true">*</span></legend>
                        <div class="max-h-48 overflow-y-auto rounded-md border border-border p-1">
                            <label v-for="m in members" :key="m.value" class="flex h-8 cursor-pointer items-center gap-2 rounded-sm px-2 text-sm text-ink hover:bg-surface-hover">
                                <input type="checkbox" class="size-4 accent-[var(--color-accent)]" :checked="form.recipients.includes(m.value)" @change="toggleRecipient(m.value)" />
                                {{ m.label }}
                            </label>
                        </div>
                        <p v-if="form.errors.recipients" class="mt-1 text-xs text-negative" role="alert">{{ form.errors.recipients }}</p>
                        <p v-else class="mt-1 text-xs text-secondary">In-app notification and email.</p>
                    </fieldset>
                    <label class="flex items-center gap-2 text-sm text-ink">
                        <input v-model="form.is_active" type="checkbox" class="size-4 accent-[var(--color-accent)]" />
                        Active
                    </label>
                </div>
            </SettingsSection>

            <div class="flex justify-end gap-2">
                <Button variant="ghost" :href="alert ? route('alerts.show', { alert: alert.id }) : route('alerts.index')">Cancel</Button>
                <Button type="submit" variant="primary" :loading="form.processing">{{ alert ? 'Save alert' : 'Create alert' }}</Button>
            </div>
        </form>
    </AppLayout>
</template>
