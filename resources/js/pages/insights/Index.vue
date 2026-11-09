<script setup lang="ts">
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { RefreshCw, ScanSearch, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ChartPanel from '@/components/analytics/ChartPanel.vue';
import ChartSkeleton from '@/components/analytics/ChartSkeleton.vue';
import EChart from '@/components/charts/EChart.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type ChartTheme, baseOption, escapeHtml, tooltipHeader, tooltipRow } from '@/lib/chart-theme';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import { changeTone, formatMetric, type MetricFormat, type Polarity } from '@/lib/metrics';
import { route } from '@/lib/route';
import type { Option } from '@/types/domain';

interface AnomalyRow {
    id: number;
    metric: string;
    metric_label: string;
    format: MetricFormat;
    polarity: Polarity;
    date: string;
    cadence: 'day' | 'month';
    actual: number;
    expected: number;
    lower: number;
    upper: number;
    z: number;
    severity: 'warning' | 'critical';
    explanation: string;
}

interface Forecast {
    metric: string;
    label: string;
    format: MetricFormat;
    history: { key: string; label: string; value: number }[];
    forecast: { key: string; label: string; value: number; lower: number; upper: number }[];
    method: string | null;
    method_label: string | null;
    backtest_mape: number | null;
    methods: { value: string; label: string; available: boolean }[];
    months: number;
    message: string | null;
}

const props = defineProps<{
    filters: { metric: string; severity: string | null; forecast: string; method: string; horizon: number };
    metrics: (Option & { cadence: 'day' | 'month'; format: MetricFormat })[];
    anomalies: AnomalyRow[];
    counts: { critical: number; warning: number };
    lastScan: string | null;
    series?: { cadence: 'day' | 'month'; keys: string[]; labels: string[]; values: (number | null)[] };
    forecast?: Forecast;
    forecastMetrics: Option[];
    canScan: boolean;
}>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const fmt = (v: number | null | undefined, format: MetricFormat, compact = false) => formatMetric(v, format, currency.value, compact);
const selected = computed(() => props.metrics.find((m) => m.value === props.filters.metric) ?? props.metrics[0]!);
const shown = computed(() => props.anomalies.filter((a) => a.metric === props.filters.metric));
const others = computed(() => props.anomalies.filter((a) => a.metric !== props.filters.metric));

function visit(patch: Partial<typeof props.filters>) {
    const next = { ...props.filters, ...patch };
    router.get(
        route('insights.index'),
        {
            metric: next.metric,
            severity: next.severity || undefined,
            forecast: next.forecast,
            method: next.method === 'auto' ? undefined : next.method,
            horizon: next.horizon === 6 ? undefined : next.horizon,
        },
        { preserveScroll: true, preserveState: true },
    );
}

const scanning = ref(false);
function scan() {
    scanning.value = true;
    router.post(route('insights.scan'), {}, { preserveScroll: true, onFinish: () => (scanning.value = false) });
}

const when = (a: Pick<AnomalyRow, 'date' | 'cadence'>) =>
    a.cadence === 'month' ? formatDate(`${a.date}T00:00:00`, { month: 'long', year: 'numeric' }) : formatDate(`${a.date}T00:00:00`, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
const toneClass = { positive: 'text-positive', negative: 'text-negative', neutral: 'text-secondary' } as const;
const deviation = (a: AnomalyRow) => (a.expected ? ((a.actual - a.expected) / Math.abs(a.expected)) * 100 : null);

// ---- Anomaly chart: actual values with flagged days marked --------------------
function anomalyOption(theme: ChartTheme) {
    const s = props.series!;
    const base = baseOption(theme);
    const format = selected.value.format;
    const byKey = new Map(shown.value.map((a) => [props.series!.cadence === 'month' ? a.date.slice(0, 7) : a.date, a]));
    const warn = getComputedStyle(document.documentElement).getPropertyValue('--warning').trim() || '#B45309';
    const markers = s.keys.map((k, i) => {
        const a = byKey.get(k);
        return a ? { value: s.values[i], itemStyle: { color: a.severity === 'critical' ? theme.negative : warn, borderColor: theme.surface, borderWidth: 2 } } : null;
    });

    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            formatter: (params: { dataIndex: number }[]) => {
                const i = params[0]?.dataIndex ?? 0;
                const a = byKey.get(s.keys[i]!);
                let html = tooltipHeader(s.labels[i] ?? '') + tooltipRow(theme.series[0]!, selected.value.label, fmt(s.values[i], format), true);
                if (a) {
                    html += tooltipRow(theme.muted, 'Expected', `${fmt(a.lower, format, true)} – ${fmt(a.upper, format, true)}`);
                    html += `<div style="margin-top:4px;font-size:11px;color:${a.severity === 'critical' ? theme.negative : warn}">${escapeHtml(a.severity === 'critical' ? 'Critical anomaly' : 'Warning')}</div>`;
                }
                return html;
            },
        },
        xAxis: { type: 'category', data: s.labels, ...base.categoryAxis },
        yAxis: { type: 'value', ...base.valueAxis, axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => fmt(v, format, true) } },
        series: [
            { type: 'line', data: s.values, showSymbol: false, lineStyle: { width: 1.75, color: theme.series[0] }, itemStyle: { color: theme.series[0] }, connectNulls: false },
            { type: 'line', data: markers, showSymbol: true, symbolSize: 10, lineStyle: { width: 0 }, tooltip: { show: false }, z: 3 },
        ],
    };
}

// ---- Forecast chart: history, estimate and its band ---------------------------
function forecastOption(theme: ChartTheme) {
    const f = props.forecast!;
    const base = baseOption(theme);
    const labels = [...f.history.map((h) => h.label), ...f.forecast.map((p) => p.label)];
    const n = f.history.length;
    const pad = (arr: (number | null)[], before: number) => [...Array<null>(before).fill(null), ...arr];
    const last = f.history.at(-1)?.value ?? null;
    const lower = pad([last, ...f.forecast.map((p) => p.lower)], n - 1);
    const width = pad([0, ...f.forecast.map((p) => p.upper - p.lower)], n - 1);
    const estimate = pad([last, ...f.forecast.map((p) => p.value)], n - 1);

    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            formatter: (params: { dataIndex: number }[]) => {
                const i = params[0]?.dataIndex ?? 0;
                if (i < n) return tooltipHeader(labels[i] ?? '') + tooltipRow(theme.series[0]!, 'Actual', fmt(f.history[i]?.value, f.format), true);
                const p = f.forecast[i - n]!;
                return (
                    tooltipHeader(`${p.label} · Estimate`) +
                    tooltipRow(theme.series[0]!, 'Forecast', fmt(p.value, f.format), true) +
                    tooltipRow(theme.muted, '95% range', `${fmt(p.lower, f.format, true)} – ${fmt(p.upper, f.format, true)}`)
                );
            },
        },
        xAxis: { type: 'category', data: labels, ...base.categoryAxis },
        yAxis: { type: 'value', ...base.valueAxis, axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => fmt(v, f.format, true) } },
        series: [
            { type: 'line', data: lower, stack: 'band', showSymbol: false, lineStyle: { opacity: 0 }, tooltip: { show: false } },
            { type: 'line', data: width, stack: 'band', showSymbol: false, lineStyle: { opacity: 0 }, areaStyle: { color: theme.series[0], opacity: 0.12 }, tooltip: { show: false } },
            { type: 'line', data: f.history.map((h) => h.value), showSymbol: false, lineStyle: { width: 2, color: theme.series[0] }, itemStyle: { color: theme.series[0] } },
            { type: 'line', data: estimate, showSymbol: true, symbolSize: 5, lineStyle: { width: 2, type: 'dashed', color: theme.series[0] }, itemStyle: { color: theme.series[0] } },
        ],
    };
}

const methodOptions = computed<Option[]>(() => [
    { value: 'auto', label: 'Best fit (auto)' },
    ...(props.forecast?.methods ?? []).map((m) => ({ value: m.value, label: m.available ? m.label : `${m.label} — needs more history` })),
]);
</script>

<template>
    <AppLayout title="Insights">
        <template #actions>
            <span v-if="lastScan" class="hidden text-xs text-secondary sm:inline">Last scan {{ formatRelative(lastScan) }}</span>
            <Button v-if="canScan" :loading="scanning" @click="scan"><RefreshCw />Scan now</Button>
        </template>

        <!-- Anomalies -->
        <section aria-labelledby="anomalies-heading">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="anomalies-heading" class="text-sm font-semibold text-ink">Anomalies</h2>
                    <p class="text-xs text-secondary">
                        Days (months, for expenses) outside the normal range, from the last 90 days ·
                        <span class="text-negative">{{ counts.critical }} critical</span> · <span class="text-warning">{{ counts.warning }} warning</span>
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <NativeSelect :model-value="filters.metric" :options="metrics" aria-label="Metric" class="w-48" @update:model-value="(v) => visit({ metric: String(v) })" />
                    <NativeSelect
                        :model-value="filters.severity ?? ''"
                        :options="[{ value: '', label: 'All severities' }, { value: 'critical', label: 'Critical only' }, { value: 'warning', label: 'Warnings only' }]"
                        aria-label="Severity"
                        class="w-40"
                        @update:model-value="(v) => visit({ severity: v ? String(v) : null })"
                    />
                </div>
            </div>

            <ChartPanel :title="`${selected.label} — ${selected.cadence === 'month' ? 'last 15 months' : 'last 90 days'}`" :meta="currency">
                <template #legend>
                    <span class="hidden items-center gap-3 text-xs text-secondary md:flex">
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-full bg-negative" aria-hidden="true" />Critical</span>
                        <span class="flex items-center gap-1"><span class="size-2.5 rounded-full bg-warning" aria-hidden="true" />Warning</span>
                    </span>
                </template>
                <Deferred data="series">
                    <template #fallback><ChartSkeleton :height="240" /></template>
                    <EChart v-if="series" :key="`${filters.metric}-${filters.severity}`" :option="anomalyOption" :height="240" :aria-label="`${selected.label} with ${shown.length} anomalies marked`" />
                </Deferred>
            </ChartPanel>

            <ul v-if="shown.length" class="mt-3 divide-y divide-border rounded-lg border border-border bg-surface">
                <li v-for="a in shown" :key="a.id" class="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:gap-4">
                    <div class="flex w-48 shrink-0 items-center gap-2">
                        <Badge :tone="a.severity === 'critical' ? 'negative' : 'warning'" dot>{{ a.severity === 'critical' ? 'Critical' : 'Warning' }}</Badge>
                        <span class="text-xs text-secondary">{{ when(a) }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink">
                            <span class="num font-semibold">{{ fmt(a.actual, a.format) }}</span>
                            <span class="text-secondary"> vs expected {{ fmt(a.lower, a.format, true) }} – {{ fmt(a.upper, a.format, true) }}</span>
                            <span :class="toneClass[changeTone(deviation(a), a.polarity)]" class="num ml-1.5 text-xs">{{ (deviation(a) ?? 0) > 0 ? '+' : '' }}{{ formatNumber(deviation(a), 1) }}%</span>
                        </p>
                        <p class="mt-0.5 text-xs text-secondary">{{ a.explanation }}</p>
                    </div>
                    <span class="num shrink-0 text-xs text-muted" :title="'Standard deviations from the expected value'">z {{ a.z > 0 ? '+' : '' }}{{ formatNumber(a.z, 1) }}</span>
                </li>
            </ul>
            <div v-else class="mt-3 rounded-lg border border-border bg-surface px-4 py-8">
                <EmptyState
                    :icon="ScanSearch"
                    :title="lastScan ? `No ${selected.label.toLowerCase()} anomalies` : 'Not scanned yet'"
                    :description="lastScan ? 'Everything in the scanned period was inside its normal range.' : 'Anomalies are detected nightly. Run a scan now to see results immediately.'"
                />
            </div>
            <p v-if="others.length" class="mt-2 text-xs text-secondary">
                Also flagged:
                <template v-for="(m, i) in metrics.filter((m) => m.value !== filters.metric)" :key="m.value">
                    <button type="button" class="text-accent-ink hover:underline" @click="visit({ metric: m.value })">{{ m.label }} ({{ others.filter((a) => a.metric === m.value).length }})</button><template v-if="i < metrics.length - 2"> · </template>
                </template>
            </p>
        </section>

        <!-- Forecast -->
        <section class="mt-10" aria-labelledby="forecast-heading">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 id="forecast-heading" class="flex items-center gap-2 text-sm font-semibold text-ink">Forecast <Badge tone="accent">Estimate</Badge></h2>
                    <p class="text-xs text-secondary">Statistical projection from complete months. Not a target or a guarantee.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <NativeSelect :model-value="filters.forecast" :options="forecastMetrics" aria-label="Forecast metric" class="w-44" @update:model-value="(v) => visit({ forecast: String(v) })" />
                    <NativeSelect :model-value="filters.method" :options="methodOptions" aria-label="Method" class="w-64" @update:model-value="(v) => visit({ method: String(v) })" />
                    <NativeSelect
                        :model-value="String(filters.horizon)"
                        :options="[{ value: '3', label: 'Next 3 months' }, { value: '6', label: 'Next 6 months' }, { value: '12', label: 'Next 12 months' }]"
                        aria-label="Horizon"
                        class="w-40"
                        @update:model-value="(v) => visit({ horizon: Number(v) })"
                    />
                </div>
            </div>

            <Deferred data="forecast">
                <template #fallback><ChartSkeleton :height="280" /></template>
                <template v-if="forecast">
                    <div v-if="forecast.message" class="rounded-lg border border-border bg-surface px-4 py-8">
                        <EmptyState :icon="TrendingUp" title="Not enough history" :description="forecast.message" />
                    </div>
                    <div v-else class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                        <ChartPanel :title="`${forecast.label} — estimate`" :meta="`${forecast.method_label} · ${forecast.months} months of history`">
                            <EChart :key="`${forecast.metric}-${forecast.method}-${forecast.forecast.length}`" :option="forecastOption" :height="280" :aria-label="`${forecast.label} history and ${forecast.forecast.length}-month estimate`" />
                        </ChartPanel>
                        <div class="rounded-lg border border-border bg-surface">
                            <table class="w-full text-sm">
                                <caption class="sr-only">{{ forecast.label }} forecast values</caption>
                                <thead class="bg-surface-sunken text-xs text-secondary">
                                    <tr class="h-8">
                                        <th class="pl-4 text-left font-medium">Month</th>
                                        <th class="px-3 text-right font-medium">Estimate</th>
                                        <th class="pr-4 text-right font-medium">95% range</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="p in forecast.forecast" :key="p.key" class="h-9">
                                        <td class="pl-4 text-secondary">{{ p.label }}</td>
                                        <td class="num px-3 text-right font-medium text-ink">{{ fmt(p.value, forecast.format, true) }}</td>
                                        <td class="num pr-4 text-right text-xs text-secondary">{{ fmt(p.lower, forecast.format, true) }} – {{ fmt(p.upper, forecast.format, true) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <p class="border-t border-border px-4 py-3 text-xs text-secondary">
                                <template v-if="forecast.backtest_mape !== null">
                                    On the last 3 known months this method was off by {{ formatNumber(forecast.backtest_mape, 1) }}% on average.
                                    <template v-if="forecast.backtest_mape > 25"> Treat this estimate with caution.</template>
                                </template>
                                <template v-else>Not enough history to back-test this method.</template>
                                <span v-if="filters.method === 'auto'"> Chosen automatically as the most accurate available method.</span>
                            </p>
                        </div>
                    </div>
                </template>
            </Deferred>
        </section>
    </AppLayout>
</template>
