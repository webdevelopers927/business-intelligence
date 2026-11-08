<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AreaChart, BarChart3, ChartPie, CircleDot, Gauge, LineChart, ListOrdered, Table2 } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import MultiSelectFilter from '@/components/analytics/MultiSelectFilter.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { AnalyticsOptions } from '@/types/analytics';
import type { Catalog, Widget, WidgetType } from '@/types/dashboards';

/**
 * Add/edit a widget. Choices narrow each other — the dimension list only
 * offers what the chosen type and metric support — and the server
 * re-validates the same rules.
 */
const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{ dashboardId: number; widget: Widget | null; catalog: Catalog; options: AnalyticsOptions }>();

const types: { value: WidgetType; label: string; icon: typeof Gauge; hint: string }[] = [
    { value: 'kpi', label: 'KPI', icon: Gauge, hint: 'One number with change' },
    { value: 'line', label: 'Line', icon: LineChart, hint: 'Trend over time' },
    { value: 'area', label: 'Area', icon: AreaChart, hint: 'Trend with volume' },
    { value: 'bar', label: 'Bar', icon: BarChart3, hint: 'Compare values' },
    { value: 'donut', label: 'Donut', icon: CircleDot, hint: 'Share of total' },
    { value: 'pie', label: 'Pie', icon: ChartPie, hint: 'Share of total' },
    { value: 'table', label: 'Table', icon: Table2, hint: 'Exact values' },
    { value: 'ranked_list', label: 'Ranked list', icon: ListOrdered, hint: 'Top items' },
];

const form = useForm({
    type: 'kpi' as WidgetType,
    title: '',
    metric: 'revenue',
    dimension: '' as string,
    range_mode: 'inherit' as 'inherit' | 'fixed',
    range_preset: 'last_30_days',
    comparison: true,
    limit: 8,
    branches: [] as number[],
    categories: [] as number[],
});

watch(open, (isOpen) => {
    if (!isOpen) return;
    form.clearErrors();
    const w = props.widget;
    form.defaults({
        type: w?.type ?? 'kpi',
        title: w?.title ?? '',
        metric: w?.config.metric ?? 'revenue',
        dimension: w?.config.dimension ?? '',
        range_mode: w?.config.range.mode ?? 'inherit',
        range_preset: w?.config.range.preset ?? 'last_30_days',
        comparison: w?.config.comparison ?? true,
        limit: w?.config.limit ?? 8,
        branches: w?.config.filters.branches ?? [],
        categories: w?.config.filters.categories ?? [],
    });
    form.reset();
});

const typeDimensions = computed(() => props.catalog.types.find((t) => t.value === form.type)?.dimensions ?? []);
const needsDimension = computed(() => !typeDimensions.value.includes(null));
const metric = computed(() => props.catalog.metrics.find((m) => m.key === form.metric));
const dimensionOptions = computed(() =>
    props.catalog.dimensions.filter((d) => typeDimensions.value.includes(d.value) && (metric.value?.dimensions.includes(d.value) ?? false)),
);
const metricOptions = computed(() => [
    ...props.catalog.metrics.filter((m) => !m.custom).map((m) => ({ value: m.key, label: m.label })),
    ...props.catalog.metrics.filter((m) => m.custom).map((m) => ({ value: m.key, label: `${m.label} (custom)` })),
]);
const showsLimit = computed(() => needsDimension.value && form.dimension !== 'date');
const supportsComparison = computed(() => ['kpi', 'line', 'area', 'table', 'bar'].includes(form.type));

// Keep the dimension valid as type/metric change.
watch([() => form.type, () => form.metric], () => {
    if (!needsDimension.value) {
        form.dimension = '';
    } else if (!dimensionOptions.value.some((d) => d.value === form.dimension)) {
        form.dimension = dimensionOptions.value[0]?.value ?? '';
    }
});

const presets = computed(() => props.options.presets.filter((p) => p.value !== 'custom'));

function suggestTitle() {
    if (form.title.trim()) return;
    const m = metric.value?.label ?? 'Metric';
    const d = props.catalog.dimensions.find((x) => x.value === form.dimension)?.label;
    form.title = d && form.dimension !== 'date' ? `${m} by ${d.toLowerCase()}` : m;
}

function submit() {
    suggestTitle();
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    if (props.widget) {
        form.patch(route('dashboards.widgets.update', { dashboard: props.dashboardId, widget: props.widget.id }), options);
    } else {
        form.post(route('dashboards.widgets.store', { dashboard: props.dashboardId }), options);
    }
}
</script>

<template>
    <Dialog v-model:open="open" :title="widget ? 'Edit widget' : 'Add widget'" description="Every widget reads from the same analytics engine as the executive dashboard." size="md">
        <form id="widget-form" class="flex flex-col gap-5" novalidate @submit.prevent="submit">
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-ink">Visualization</legend>
                <div class="grid grid-cols-4 gap-1.5" role="radiogroup">
                    <button
                        v-for="t in types"
                        :key="t.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.type === t.value"
                        :title="t.hint"
                        :class="cn('flex flex-col items-center gap-1 rounded-md border px-1 py-2 text-xs transition-colors duration-[120ms]', form.type === t.value ? 'border-accent bg-accent-subtle text-accent-ink' : 'border-border-strong text-secondary hover:bg-surface-hover hover:text-ink')"
                        @click="form.type = t.value"
                    >
                        <component :is="t.icon" class="size-4" :stroke-width="1.75" aria-hidden="true" />
                        {{ t.label }}
                    </button>
                </div>
            </fieldset>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField id="w-metric" label="Metric" :error="form.errors.metric" :hint="metric?.description" required>
                    <template #default="{ id, invalid, describedBy }">
                        <NativeSelect :id="id" v-model="form.metric" :options="metricOptions" :invalid="invalid" :aria-describedby="describedBy" />
                    </template>
                </FormField>
                <FormField v-if="needsDimension" id="w-dimension" label="Break down by" :error="form.errors.dimension" required>
                    <template #default="{ id, invalid }">
                        <NativeSelect v-if="dimensionOptions.length" :id="id" v-model="form.dimension" :options="dimensionOptions" :invalid="invalid" />
                        <p v-else class="text-xs text-warning">{{ metric?.label }} can't be shown as {{ types.find((t) => t.value === form.type)?.label.toLowerCase() }}. Choose another type or metric.</p>
                    </template>
                </FormField>
            </div>

            <FormField id="w-title" label="Title" :error="form.errors.title" hint="Leave empty to name it after the metric.">
                <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.title" maxlength="80" :invalid="invalid" :aria-describedby="describedBy" /></template>
            </FormField>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField id="w-range" label="Date range" :error="form.errors.range_preset">
                    <template #default="{ id }">
                        <div class="flex flex-col gap-2">
                            <NativeSelect :id="id" v-model="form.range_mode" :options="[{ value: 'inherit', label: 'Follow dashboard' }, { value: 'fixed', label: 'Fixed range' }]" />
                            <NativeSelect v-if="form.range_mode === 'fixed'" v-model="form.range_preset" :options="presets" aria-label="Fixed range" />
                        </div>
                    </template>
                </FormField>
                <FormField v-if="showsLimit" id="w-limit" label="Items to show" :error="form.errors.limit">
                    <template #default="{ id, invalid }"><Input :id="id" v-model.number="form.limit" type="number" min="3" max="25" class="num" :invalid="invalid" /></template>
                </FormField>
            </div>

            <div>
                <p class="mb-1.5 text-sm font-medium text-ink">Widget filters</p>
                <p class="mb-2 text-xs text-secondary">Optional. When set, they replace the dashboard's filter for that dimension.</p>
                <div class="flex flex-wrap gap-2">
                    <MultiSelectFilter label="Branches" all-label="Dashboard branches" :options="options.branches" :model-value="form.branches" @update:model-value="(v) => (form.branches = v)" />
                    <MultiSelectFilter label="Categories" all-label="Dashboard categories" :options="options.categories" :model-value="form.categories" @update:model-value="(v) => (form.categories = v)" />
                </div>
            </div>

            <label v-if="supportsComparison" class="flex items-center gap-2 text-sm text-ink select-none">
                <input v-model="form.comparison" type="checkbox" class="size-4 accent-[var(--accent)]" />
                Compare with the previous period
            </label>
        </form>
        <template #footer>
            <Button @click="open = false">Cancel</Button>
            <Button type="submit" form="widget-form" variant="primary" :loading="form.processing" :disabled="needsDimension && !dimensionOptions.length">
                {{ widget ? 'Save widget' : 'Add widget' }}
            </Button>
        </template>
    </Dialog>
</template>
