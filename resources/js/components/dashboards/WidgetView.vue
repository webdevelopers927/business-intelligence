<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, RotateCw } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DeltaBadge from '@/components/analytics/DeltaBadge.vue';
import EChart from '@/components/charts/EChart.vue';
import Button from '@/components/ui/Button.vue';
import Skeleton from '@/components/ui/Skeleton.vue';
import { type ChartTheme, baseOption, seriesColor, tooltipHeader, tooltipRow } from '@/lib/chart-theme';
import { formatCompact, formatNumber } from '@/lib/format';
import { http, HttpError } from '@/lib/http';
import { formatMetric, kpiValue } from '@/lib/metrics';
import type { Widget, WidgetData } from '@/types/dashboards';

/**
 * Renders one widget. Each widget fetches its own data, so it has its own
 * loading, empty and error states and never blocks the rest of the board.
 */
const props = defineProps<{ widget: Widget; dataUrl: string; query: string }>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const data = ref<WidgetData | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const error = ref('');
let controller: AbortController | null = null;

async function load() {
    controller?.abort();
    controller = new AbortController();
    state.value = data.value ? state.value : 'loading';
    try {
        const res = await http.get<{ data: WidgetData }>(`${props.dataUrl}${props.query}`, controller.signal);
        data.value = res.data;
        state.value = 'ready';
    } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') return;
        error.value = e instanceof HttpError ? e.message : 'This widget could not be loaded.';
        state.value = 'error';
    }
}

// Reload when the dashboard filters or the widget's own config change.
watch(() => [props.query, JSON.stringify(props.widget.config), props.widget.type], load, { immediate: true });
onBeforeUnmount(() => controller?.abort());

const fmt = (v: number | null | undefined, compact = false) => formatMetric(v, data.value?.metric.format ?? 'number', currency.value, compact);
const isEmpty = computed(() => {
    const d = data.value;
    if (!d) return false;
    if (d.kind === 'breakdown') return d.rows.length === 0;
    if (d.kind === 'series') return (d.series.series[d.metric.key] ?? []).every((v) => !v);
    return false;
});

function chartOption(theme: ChartTheme) {
    const d = data.value!;
    const base = baseOption(theme);
    const type = props.widget.type;

    if (d.kind === 'series') {
        const values = d.series.series[d.metric.key] ?? [];
        const previous = d.series.previous[d.metric.key] ?? [];
        const isBar = type === 'bar';
        return {
            ...base,
            grid: { ...base.grid, top: 8 },
            tooltip: {
                ...base.tooltip,
                trigger: 'axis',
                formatter: (items: { dataIndex: number }[]) => {
                    const i = items[0]?.dataIndex ?? 0;
                    return tooltipHeader(d.series.labels[i] ?? '') + tooltipRow(theme.series[0]!, d.metric.label, fmt(values[i]), true) +
                        (previous.length ? tooltipRow(theme.muted, d.series.previous_labels[i] ?? 'Previous', fmt(previous[i])) : '');
                },
            },
            xAxis: { ...base.categoryAxis, type: 'category', boundaryGap: isBar, data: d.series.labels },
            yAxis: { ...base.valueAxis, type: 'value', axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => (d.metric.format === 'percent' ? `${v}%` : formatCompact(v)) } },
            series: [
                ...(previous.length ? [{ type: 'line', data: previous, symbol: 'none', lineStyle: { width: 1.5, type: 'dashed', color: theme.muted }, z: 1 }] : []),
                isBar
                    ? { type: 'bar', data: values, barMaxWidth: 20, itemStyle: { color: theme.series[0], borderRadius: [3, 3, 0, 0] } }
                    : {
                          type: 'line',
                          data: values,
                          symbol: 'circle',
                          symbolSize: 7,
                          showSymbol: false,
                          lineStyle: { width: 2, color: theme.series[0] },
                          itemStyle: { color: theme.series[0], borderColor: theme.surface, borderWidth: 2 },
                          areaStyle: type === 'area' ? { color: theme.series[0], opacity: 0.14 } : undefined,
                      },
            ],
        };
    }

    if (d.kind === 'breakdown' && (type === 'pie' || type === 'donut')) {
        return {
            ...base,
            tooltip: { ...base.tooltip, trigger: 'item', formatter: (p: { dataIndex: number; percent: number }) => tooltipHeader(d.rows[p.dataIndex]!.label) + tooltipRow(seriesColor(theme, d.rows[p.dataIndex]!.color_index ?? p.dataIndex), d.metric.label, fmt(d.rows[p.dataIndex]!.values[d.metric.key]), true) + tooltipRow(theme.muted, 'Share', `${formatNumber(p.percent, 1)}%`) },
            series: [{
                type: 'pie',
                radius: type === 'donut' ? ['58%', '86%'] : ['0%', '86%'],
                label: { show: false },
                itemStyle: { borderColor: theme.surface, borderWidth: 2 },
                data: d.rows.map((r, i) => ({ name: r.label, value: r.values[d.metric.key], itemStyle: { color: seriesColor(theme, r.color_index ?? i) } })),
            }],
        };
    }

    // Horizontal bar breakdown.
    const rows = d.kind === 'breakdown' ? [...d.rows].reverse() : [];
    return {
        ...base,
        grid: { ...base.grid, right: 56, top: 4 },
        tooltip: { ...base.tooltip, trigger: 'item', formatter: (p: { dataIndex: number }) => tooltipHeader(rows[p.dataIndex]!.label) + tooltipRow(seriesColor(theme, rows[p.dataIndex]!.color_index ?? 0), d.metric.label, fmt(rows[p.dataIndex]!.values[d.metric.key]), true) },
        xAxis: { ...base.valueAxis, type: 'value', axisLabel: { show: false }, splitLine: { show: false } },
        yAxis: { ...base.categoryAxis, type: 'category', data: rows.map((r) => r.label), axisLine: { show: false }, axisLabel: { ...base.categoryAxis.axisLabel, color: theme.secondary, width: 110, overflow: 'truncate' } },
        series: [{
            type: 'bar',
            barMaxWidth: 18,
            data: rows.map((r) => ({ value: r.values[d.metric.key], itemStyle: { color: r.color_index !== null ? seriesColor(theme, r.color_index) : theme.series[0], borderRadius: [0, 3, 3, 0] } })),
            label: { show: true, position: 'right', color: theme.text, fontSize: 11, formatter: (p: { value: number }) => fmt(p.value, true) },
        }],
    };
}

const change = (now: number | null | undefined, then: number | null | undefined) => (now !== null && now !== undefined && then ? ((now - then) / Math.abs(then)) * 100 : null);
const breakdownTotal = computed(() => (data.value?.kind === 'breakdown' ? data.value.rows.reduce((s, r) => s + Math.max(0, r.values[data.value!.metric.key] ?? 0), 0) : 0));
const legendShare = (v: number | null | undefined) => (breakdownTotal.value > 0 ? `${formatNumber(((v ?? 0) / breakdownTotal.value) * 100, 0)}%` : '—');
const maxValue = computed(() => (data.value?.kind === 'breakdown' ? Math.max(0, ...data.value.rows.map((r) => r.values[data.value!.metric.key] ?? 0)) : 0));
const sparkPath = computed(() => {
    if (data.value?.kind !== 'kpi') return '';
    const pts = data.value.spark.map((v) => v ?? 0);
    if (pts.length < 2) return '';
    const min = Math.min(...pts);
    const max = Math.max(...pts);
    const span = max - min || 1;
    return pts.map((v, i) => `${i === 0 ? 'M' : 'L'}${((i / (pts.length - 1)) * 100).toFixed(2)},${(28 - ((v - min) / span) * 26).toFixed(2)}`).join(' ');
});
</script>

<template>
    <div class="flex h-full min-h-0 flex-col">
        <!-- Loading -->
        <div v-if="state === 'loading'" class="flex flex-1 flex-col gap-2 p-1" aria-busy="true" aria-label="Loading widget">
            <Skeleton class="h-6 w-1/3" />
            <Skeleton class="flex-1 rounded-md" />
        </div>

        <!-- Error -->
        <div v-else-if="state === 'error'" class="flex flex-1 flex-col items-start justify-center gap-2 text-sm" role="alert">
            <p class="flex items-start gap-2 text-negative"><CircleAlert class="mt-0.5 size-4 shrink-0" />{{ error }}</p>
            <Button size="sm" variant="ghost" @click="load"><RotateCw />Retry</Button>
        </div>

        <template v-else-if="data">
            <!-- KPI -->
            <div v-if="data.kind === 'kpi'" class="flex h-full flex-col justify-between">
                <div>
                    <p class="num truncate text-kpi font-semibold tracking-[-0.02em] text-ink" :title="fmt(data.kpi.value)">{{ kpiValue({ value: data.kpi.value, format: data.metric.format }, currency) }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-secondary">
                        <DeltaBadge v-if="widget.config.comparison" :change="data.kpi.change" :polarity="data.metric.polarity" :unit="data.kpi.change_unit" />
                        <span v-if="widget.config.comparison">vs {{ data.period.previous_label }}</span>
                    </p>
                </div>
                <svg v-if="sparkPath" viewBox="0 0 100 30" preserveAspectRatio="none" class="mt-2 h-8 w-full" aria-hidden="true">
                    <path :d="sparkPath" fill="none" stroke="var(--series-1)" stroke-width="1.5" vector-effect="non-scaling-stroke" opacity="0.7" />
                </svg>
            </div>

            <p v-else-if="isEmpty" class="flex flex-1 items-center text-sm text-secondary">No data for {{ data.period.label }}.</p>

            <!-- Pie / donut: chart plus an always-visible legend (identity is never color alone) -->
            <div v-else-if="(widget.type === 'pie' || widget.type === 'donut') && data.kind === 'breakdown'" class="flex min-h-0 flex-1 items-center gap-3">
                <EChart fill :height="100" class="min-w-0 basis-1/2" :option="chartOption" :aria-label="`${widget.title}: ${data.metric.label} for ${data.period.label}`" />
                <ul class="min-w-0 basis-1/2 space-y-1 text-xs">
                    <li v-for="(row, i) in data.rows" :key="row.id" class="flex items-center gap-1.5">
                        <span class="size-2 shrink-0 rounded-[2px]" :style="{ background: `var(--series-${(row.color_index ?? i) + 1}, var(--series-other))` }" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate text-secondary">{{ row.label }}</span>
                        <span class="num text-ink">{{ legendShare(row.values[data.metric.key]) }}</span>
                    </li>
                </ul>
            </div>

            <!-- Charts -->
            <EChart
                v-else-if="['line', 'area', 'bar'].includes(widget.type)"
                fill
                :height="120"
                :option="chartOption"
                :aria-label="`${widget.title}: ${data.metric.label} for ${data.period.label}`"
            />

            <!-- Ranked list -->
            <ol v-else-if="widget.type === 'ranked_list' && data.kind === 'breakdown'" class="flex-1 divide-y divide-border overflow-y-auto">
                <li v-for="(row, i) in data.rows" :key="row.id" class="flex items-center gap-3 py-1.5 text-sm">
                    <span class="num w-5 text-right text-xs text-muted">{{ i + 1 }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-ink">{{ row.label }}</span>
                            <span class="num shrink-0 text-ink">{{ fmt(row.values[data.metric.key], true) }}</span>
                        </div>
                        <div class="mt-1 h-1 overflow-hidden rounded-full bg-surface-sunken">
                            <div class="h-full rounded-full bg-[var(--series-1)]" :style="{ width: `${maxValue ? ((row.values[data.metric.key] ?? 0) / maxValue) * 100 : 0}%` }" />
                        </div>
                    </div>
                </li>
            </ol>

            <!-- Table -->
            <div v-else-if="widget.type === 'table'" class="flex-1 overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-surface text-left text-xs text-secondary">
                        <tr class="h-7 border-b border-border">
                            <th class="font-medium">{{ data.kind === 'series' ? 'Period' : 'Name' }}</th>
                            <th class="text-right font-medium">{{ data.metric.label }}</th>
                            <th v-if="widget.config.comparison" class="text-right font-medium">Change</th>
                        </tr>
                    </thead>
                    <tbody v-if="data.kind === 'breakdown'" class="divide-y divide-border">
                        <tr v-for="row in data.rows" :key="row.id" class="h-8">
                            <td class="max-w-0 truncate pr-2">{{ row.label }}</td>
                            <td class="num text-right">{{ fmt(row.values[data.metric.key]) }}</td>
                            <td v-if="widget.config.comparison" class="text-right"><DeltaBadge :change="change(row.values[data.metric.key], row.previous?.[data.metric.key])" :polarity="data.metric.polarity" /></td>
                        </tr>
                    </tbody>
                    <tbody v-else-if="data.kind === 'series'" class="divide-y divide-border">
                        <tr v-for="(label, i) in data.series.labels" :key="label" class="h-8">
                            <td>{{ label }}</td>
                            <td class="num text-right">{{ fmt(data.series.series[data.metric.key]?.[i]) }}</td>
                            <td v-if="widget.config.comparison" class="text-right"><DeltaBadge :change="change(data.series.series[data.metric.key]?.[i], data.series.previous[data.metric.key]?.[i])" :polarity="data.metric.polarity" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="data.ignored_filters.length" class="mt-1 text-[11px] text-muted">Category filter not applicable to {{ data.metric.label.toLowerCase() }}.</p>
        </template>
    </div>
</template>
