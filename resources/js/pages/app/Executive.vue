<script setup lang="ts">
import { Deferred, Link, router, usePage } from '@inertiajs/vue3';
import { Compass } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import ChartPanel from '@/components/analytics/ChartPanel.vue';
import ChartSkeleton from '@/components/analytics/ChartSkeleton.vue';
import DeltaBadge from '@/components/analytics/DeltaBadge.vue';
import FilterBar from '@/components/analytics/FilterBar.vue';
import InsightList, { type Insight } from '@/components/analytics/InsightList.vue';
import KpiStrip from '@/components/analytics/KpiStrip.vue';
import EChart from '@/components/charts/EChart.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type ChartTheme, baseOption, seriesColor, tooltipHeader, tooltipRow } from '@/lib/chart-theme';
import { formatCompact, formatDate, formatNumber, formatPercentChange } from '@/lib/format';
import { formatMetric, type Kpi, kpiValue } from '@/lib/metrics';
import { route } from '@/lib/route';
import type { AnalyticsFilterState, AnalyticsOptions, BreakdownRow, Period, RecentTransaction, Series, TopProduct } from '@/types/analytics';

const props = defineProps<{
    filters: AnalyticsFilterState;
    options: AnalyticsOptions;
    period: Period;
    kpis: Kpi[];
    secondary: Kpi[];
    trend?: { revenue: Series; revenue_vs_expenses: Series };
    breakdowns?: { branches: BreakdownRow[]; categories: BreakdownRow[]; explore_month: string };
    tables?: { top_products: TopProduct[]; recent_transactions: RecentTransaction[] };
    insights?: Insight[];
}>();

const page = usePage();
const currency = computed(() => page.props.tenant?.currency ?? 'PKR');
const money = (v: number | null | undefined) => formatMetric(v, 'currency', currency.value);
const compactMoney = (v: number | null | undefined) => formatMetric(v, 'currency', currency.value, true);
const comparisonShort = computed(() => props.period.previous.label);

// ── Revenue overview: current vs previous period ───────────────────────
function revenueOption(theme: ChartTheme) {
    const s = props.trend!.revenue;
    const base = baseOption(theme);
    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            axisPointer: { type: 'line', lineStyle: { color: theme.borderStrong } },
            formatter: (items: { dataIndex: number }[]) => {
                const i = items[0]?.dataIndex ?? 0;
                const now = s.series.revenue?.[i] ?? null;
                const then = s.previous.revenue?.[i] ?? null;
                const change = now !== null && then ? ((now - then) / Math.abs(then)) * 100 : null;
                return (
                    tooltipHeader(s.labels[i] ?? '') +
                    tooltipRow(theme.series[0]!, 'Revenue', money(now), true) +
                    tooltipRow(theme.muted, s.previous_labels[i] ?? 'Previous', money(then)) +
                    `<div style="margin-top:4px;font-size:11px;color:var(--text-secondary)">Change ${formatPercentChange(change)}</div>`
                );
            },
        },
        xAxis: { ...base.categoryAxis, type: 'category', boundaryGap: false, data: s.labels },
        yAxis: { ...base.valueAxis, type: 'value', axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => formatCompact(v) } },
        series: [
            {
                name: 'Previous period',
                type: 'line',
                data: s.previous.revenue,
                symbol: 'none',
                lineStyle: { width: 1.5, type: 'dashed', color: theme.muted },
                z: 1,
            },
            {
                name: 'Revenue',
                type: 'line',
                data: s.series.revenue,
                symbol: 'circle',
                symbolSize: 8,
                showSymbol: false,
                lineStyle: { width: 2, color: theme.series[0] },
                itemStyle: { color: theme.series[0], borderColor: theme.surface, borderWidth: 2 },
                areaStyle: { color: theme.series[0], opacity: 0.12 },
                z: 2,
            },
        ],
    };
}

function exploreBucket(index: number) {
    const key = props.trend?.revenue.keys[index];
    if (key) router.visit(route('explore', { month: key.slice(0, 7) }));
}

// ── Revenue vs expenses: trailing 12 months, one currency axis ──────────
function revenueExpensesOption(theme: ChartTheme) {
    const s = props.trend!.revenue_vs_expenses;
    const base = baseOption(theme);
    const names = { revenue: 'Revenue', operating_expenses: 'Operating expenses', net_profit: 'Net profit' } as const;
    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            axisPointer: { type: 'shadow', shadowStyle: { color: theme.dark ? 'rgba(255,255,255,0.04)' : 'rgba(16,24,40,0.04)' } },
            formatter: (items: { dataIndex: number }[]) => {
                const i = items[0]?.dataIndex ?? 0;
                return (
                    tooltipHeader(s.labels[i] ?? '') +
                    tooltipRow(theme.series[0]!, names.revenue, money(s.series.revenue?.[i])) +
                    tooltipRow(theme.series[1]!, names.operating_expenses, money(s.series.operating_expenses?.[i])) +
                    tooltipRow(theme.series[2]!, names.net_profit, money(s.series.net_profit?.[i]), true)
                );
            },
        },
        xAxis: { ...base.categoryAxis, type: 'category', data: s.labels.map((l) => l.replace(/ \d{4}$/, '')) },
        yAxis: { ...base.valueAxis, type: 'value', axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => formatCompact(v) } },
        series: [
            { name: names.revenue, type: 'bar', data: s.series.revenue, barMaxWidth: 14, barGap: '15%', itemStyle: { color: theme.series[0], borderRadius: [3, 3, 0, 0] } },
            { name: names.operating_expenses, type: 'bar', data: s.series.operating_expenses, barMaxWidth: 14, itemStyle: { color: theme.series[1], borderRadius: [3, 3, 0, 0] } },
            {
                name: names.net_profit,
                type: 'line',
                data: s.series.net_profit,
                symbol: 'circle',
                symbolSize: 6,
                lineStyle: { width: 2, color: theme.series[2] },
                itemStyle: { color: theme.series[2], borderColor: theme.surface, borderWidth: 2 },
            },
        ],
    };
}

// ── Branch performance ─────────────────────────────────────────────────
const branchRows = computed(() => props.breakdowns?.branches ?? []);
function branchOption(theme: ChartTheme) {
    const rows = [...branchRows.value].reverse();
    const base = baseOption(theme);
    return {
        ...base,
        grid: { ...base.grid, left: 4, right: 56, top: 4, bottom: 0 },
        tooltip: {
            ...base.tooltip,
            trigger: 'item',
            formatter: (p: { dataIndex: number }) => {
                const r = rows[p.dataIndex]!;
                return tooltipHeader(r.label) + tooltipRow(seriesColor(theme, r.color_index), 'Revenue', money(r.values.revenue), true) +
                    tooltipRow(theme.muted, 'Previous', money(r.previous?.revenue)) +
                    '<div style="margin-top:4px;font-size:11px;color:var(--text-secondary)">Click to break down by category</div>';
            },
        },
        xAxis: { ...base.valueAxis, type: 'value', axisLabel: { show: false }, splitLine: { show: false } },
        yAxis: { ...base.categoryAxis, type: 'category', data: rows.map((r) => r.label.split(' — ')[0]), axisLine: { show: false }, axisLabel: { ...base.categoryAxis.axisLabel, color: theme.secondary } },
        series: [{
            type: 'bar',
            barMaxWidth: 22,
            cursor: 'pointer',
            data: rows.map((r) => ({ value: r.values.revenue, itemStyle: { color: seriesColor(theme, r.color_index), borderRadius: [0, 4, 4, 0] } })),
            label: { show: true, position: 'right', color: theme.text, fontSize: 11, formatter: (p: { value: number }) => formatCompact(p.value) },
        }],
    };
}

function exploreBranch(index: number) {
    const rows = [...branchRows.value].reverse();
    const row = rows[index];
    if (row && props.breakdowns) router.visit(route('explore', { month: props.breakdowns.explore_month, branch: row.id }));
}

// ── Sales by category (donut with interactive HTML legend) ─────────────
const hiddenCategories = ref<Set<number | string>>(new Set());
const categoryRows = computed(() => props.breakdowns?.categories ?? []);
const visibleCategoryTotal = computed(() => categoryRows.value.filter((c) => !hiddenCategories.value.has(c.id)).reduce((s, c) => s + (c.values.revenue ?? 0), 0));
function toggleCategory(id: number | string) {
    const next = new Set(hiddenCategories.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    hiddenCategories.value = next;
}
function categoryOption(theme: ChartTheme) {
    const base = baseOption(theme);
    const rows = categoryRows.value.filter((c) => !hiddenCategories.value.has(c.id));
    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'item',
            formatter: (p: { dataIndex: number }) => {
                const r = rows[p.dataIndex]!;
                const share = visibleCategoryTotal.value ? ((r.values.revenue ?? 0) / visibleCategoryTotal.value) * 100 : 0;
                return tooltipHeader(r.label) + tooltipRow(seriesColor(theme, r.color_index), 'Revenue', money(r.values.revenue), true) +
                    tooltipRow(theme.muted, 'Share', `${formatNumber(share, 1)}%`) + tooltipRow(theme.muted, 'Units', formatNumber(r.values.units_sold));
            },
        },
        series: [{
            type: 'pie',
            radius: ['62%', '88%'],
            avoidLabelOverlap: true,
            label: { show: false },
            itemStyle: { borderColor: theme.surface, borderWidth: 2 },
            emphasis: { scale: true, scaleSize: 4 },
            data: rows.map((r) => ({ name: r.label, value: r.values.revenue, itemStyle: { color: seriesColor(theme, r.color_index) } })),
        }],
    };
}

// ── Tables ─────────────────────────────────────────────────────────────
// Share bars are scaled to the largest share so the leader fills the track.
const maxShare = computed(() => Math.max(0, ...(props.tables?.top_products ?? []).map((p) => p.share ?? 0)));
const statusTone = { completed: 'positive', refunded: 'warning', cancelled: 'neutral', pending: 'accent' } as const;
const attainment = (r: BreakdownRow) => (r.target ? ((r.values.revenue ?? 0) / r.target) * 100 : null);
const branchChange = (r: BreakdownRow) => (r.previous?.revenue ? (((r.values.revenue ?? 0) - r.previous.revenue) / Math.abs(r.previous.revenue)) * 100 : null);
</script>

<template>
    <AppLayout title="Executive overview">
        <template #actions>
            <Button :href="route('explore')"><Compass />Explore</Button>
        </template>
        <template #filters>
            <FilterBar :filters="filters" :options="options" :period="period" />
        </template>

        <KpiStrip :kpis="kpis" :comparison-label="comparisonShort" />

        <!-- Secondary metrics: a quiet line, not more cards -->
        <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-2 px-1 text-xs">
            <div v-for="m in secondary" :key="m.key" class="flex items-baseline gap-2">
                <dt class="text-secondary">{{ m.label }}</dt>
                <dd class="num font-semibold text-ink">{{ kpiValue(m, currency) }}</dd>
                <dd><DeltaBadge :change="m.change" :polarity="m.polarity" :unit="m.change_unit" /></dd>
            </div>
        </dl>

        <div class="mt-6 grid grid-cols-12 gap-4">
            <!-- Revenue overview -->
            <ChartPanel title="Revenue overview" :meta="`${currency} · ${period.granularity}`" has-table class="col-span-12 xl:col-span-8">
                <template #legend>
                    <span class="hidden items-center gap-3 text-xs text-secondary sm:flex">
                        <span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded bg-[var(--series-1)]" />{{ period.current.label }}</span>
                        <span class="flex items-center gap-1.5"><span class="w-3 border-t border-dashed border-muted" />{{ period.previous.label }}</span>
                    </span>
                </template>
                <Deferred data="trend">
                    <template #fallback><ChartSkeleton /></template>
                    <EChart
                        v-if="trend"
                        fill
                        :option="revenueOption"
                        :aria-label="`Revenue for ${period.current.label}: ${money(kpis[0]?.value)}, ${formatPercentChange(kpis[0]?.change ?? null)} vs ${period.previous.label}`"
                        @select="(p) => exploreBucket(p.dataIndex)"
                    />
                    <p class="mt-1 text-xs text-muted">Click a point to explore that month by branch.</p>
                </Deferred>
                <template #table>
                    <div v-if="trend" class="max-h-[280px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-surface text-left text-xs text-secondary"><tr class="h-8"><th>Period</th><th class="text-right">Revenue</th><th class="text-right">Previous</th></tr></thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="(label, i) in trend.revenue.labels" :key="label" class="h-8">
                                    <td>{{ label }}</td>
                                    <td class="num text-right">{{ money(trend.revenue.series.revenue?.[i]) }}</td>
                                    <td class="num text-right text-secondary">{{ money(trend.revenue.previous.revenue?.[i]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </ChartPanel>

            <!-- Insights -->
            <ChartPanel title="Insights" meta="Rule-based" flush class="col-span-12 xl:col-span-4">
                <Deferred data="insights">
                    <template #fallback><div class="p-4"><ChartSkeleton kind="rows" /></div></template>
                    <InsightList :insights="insights ?? []" />
                </Deferred>
            </ChartPanel>

            <!-- Revenue vs expenses -->
            <ChartPanel title="Revenue vs expenses" :meta="`${currency} · last 12 months`" has-table class="col-span-12 lg:col-span-7">
                <template #legend>
                    <span class="hidden items-center gap-3 text-xs text-secondary sm:flex">
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-[var(--series-1)]" />Revenue</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-[var(--series-2)]" />Expenses</span>
                        <span class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded bg-[var(--series-3)]" />Net profit</span>
                    </span>
                </template>
                <Deferred data="trend">
                    <template #fallback><ChartSkeleton /></template>
                    <EChart v-if="trend" :option="revenueExpensesOption" aria-label="Monthly revenue, operating expenses and net profit for the last 12 months" />
                </Deferred>
                <template #table>
                    <div v-if="trend" class="max-h-[280px] overflow-y-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-surface text-left text-xs text-secondary"><tr class="h-8"><th>Month</th><th class="text-right">Revenue</th><th class="text-right">Expenses</th><th class="text-right">Net profit</th></tr></thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="(label, i) in trend.revenue_vs_expenses.labels" :key="label" class="h-8">
                                    <td>{{ label }}</td>
                                    <td class="num text-right">{{ compactMoney(trend.revenue_vs_expenses.series.revenue?.[i]) }}</td>
                                    <td class="num text-right">{{ compactMoney(trend.revenue_vs_expenses.series.operating_expenses?.[i]) }}</td>
                                    <td class="num text-right">{{ compactMoney(trend.revenue_vs_expenses.series.net_profit?.[i]) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </ChartPanel>

            <!-- Branch performance -->
            <ChartPanel title="Branch performance" :meta="period.current.label" class="col-span-12 lg:col-span-5">
                <Deferred data="breakdowns">
                    <template #fallback><ChartSkeleton :height="140" /><ChartSkeleton kind="rows" /></template>
                    <template v-if="branchRows.length">
                        <EChart :option="branchOption" :height="Math.max(96, branchRows.length * 40)" aria-label="Revenue by branch" @select="(p) => exploreBranch(p.dataIndex)" />
                        <table class="mt-3 w-full text-sm">
                            <thead class="text-left text-xs text-secondary"><tr class="h-7"><th class="font-medium">Branch</th><th class="text-right font-medium">Change</th><th class="text-right font-medium">Net profit</th><th class="text-right font-medium">Target</th></tr></thead>
                            <tbody class="divide-y divide-border border-t border-border">
                                <tr v-for="r in branchRows" :key="r.id" class="h-9">
                                    <td>
                                        <Link :href="route('explore', { month: breakdowns!.explore_month, branch: r.id })" class="flex items-center gap-2 hover:text-accent-ink">
                                            <span class="size-2 rounded-[2px]" :style="{ background: `var(--series-${(r.color_index ?? 7) + 1}, var(--series-other))` }" aria-hidden="true" />
                                            <span class="truncate">{{ r.label }}</span>
                                        </Link>
                                    </td>
                                    <td class="text-right"><DeltaBadge :change="branchChange(r)" polarity="up_good" /></td>
                                    <td class="num text-right" :class="(r.values.net_profit ?? 0) < 0 ? 'text-negative' : 'text-ink'">{{ compactMoney(r.values.net_profit) }}</td>
                                    <td class="num text-right" :title="r.target ? `Prorated target ${money(r.target)}` : 'No target set'">
                                        <span v-if="attainment(r) !== null" :class="attainment(r)! >= 100 ? 'text-positive' : attainment(r)! >= 90 ? 'text-ink' : 'text-negative'">{{ formatNumber(attainment(r), 0) }}%</span>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </template>
                    <p v-else class="py-6 text-sm text-secondary">No branch sales in this period.</p>
                </Deferred>
            </ChartPanel>

            <!-- Sales by category -->
            <ChartPanel title="Sales by category" :meta="`${currency} · share of revenue`" class="col-span-12 lg:col-span-5">
                <Deferred data="breakdowns">
                    <template #fallback><ChartSkeleton :height="220" /></template>
                    <div v-if="categoryRows.length" class="grid items-center gap-4 sm:grid-cols-[160px_minmax(0,1fr)]">
                        <div class="relative">
                            <EChart :option="categoryOption" :height="160" aria-label="Revenue share by product category" />
                            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                                <span class="num text-md font-semibold text-ink">{{ compactMoney(visibleCategoryTotal) }}</span>
                                <span class="label-instrument">{{ hiddenCategories.size ? 'Selected' : 'Total' }}</span>
                            </div>
                        </div>
                        <ul class="divide-y divide-border text-sm" aria-label="Categories (click to show or hide)">
                            <li v-for="c in categoryRows" :key="c.id">
                                <button
                                    type="button"
                                    class="flex h-8 w-full items-center gap-2 text-left hover:bg-surface-hover"
                                    :aria-pressed="!hiddenCategories.has(c.id)"
                                    @click="toggleCategory(c.id)"
                                >
                                    <span
                                        class="size-2 shrink-0 rounded-[2px]"
                                        :style="{ background: hiddenCategories.has(c.id) ? 'transparent' : `var(--series-${(c.color_index ?? 7) + 1}, var(--series-other))`, outline: hiddenCategories.has(c.id) ? '1px solid var(--border-strong)' : 'none' }"
                                        aria-hidden="true"
                                    />
                                    <span :class="['flex-1 truncate', hiddenCategories.has(c.id) ? 'text-muted line-through' : 'text-ink']">{{ c.label }}</span>
                                    <span class="num w-12 text-right text-secondary">{{ formatNumber(c.share, 1) }}%</span>
                                    <span class="num w-16 text-right text-ink">{{ compactMoney(c.values.revenue) }}</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                    <p v-else class="py-6 text-sm text-secondary">No category sales in this period.</p>
                </Deferred>
            </ChartPanel>

            <!-- Top products -->
            <ChartPanel title="Top products" :meta="`by revenue · ${period.current.label}`" flush class="col-span-12 lg:col-span-7">
                <Deferred data="tables">
                    <template #fallback><div class="p-4"><ChartSkeleton kind="rows" /></div></template>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-sunken text-left text-xs text-secondary">
                                <tr class="h-8">
                                    <th class="w-8 pl-4 text-right font-medium">#</th>
                                    <th class="px-3 font-medium">Product</th>
                                    <th class="hidden px-3 font-medium md:table-cell">Category</th>
                                    <th class="px-3 text-right font-medium">Units</th>
                                    <th class="px-3 text-right font-medium">Revenue</th>
                                    <th class="w-36 pr-4 font-medium">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="(p, i) in tables?.top_products ?? []" :key="p.id" class="h-9 hover:bg-surface-hover">
                                    <td class="num pl-4 text-right text-muted">{{ i + 1 }}</td>
                                    <td class="max-w-64 px-3">
                                        <span class="block truncate text-ink">{{ p.name }}</span>
                                    </td>
                                    <td class="hidden px-3 text-secondary md:table-cell">{{ p.category }}</td>
                                    <td class="num px-3 text-right">{{ formatNumber(p.units) }}</td>
                                    <td class="num px-3 text-right text-ink">{{ compactMoney(p.revenue) }}</td>
                                    <td class="pr-4">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-sunken">
                                                <div class="h-full rounded-full bg-[var(--series-1)]" :style="{ width: `${maxShare ? ((p.share ?? 0) / maxShare) * 100 : 0}%` }" />
                                            </div>
                                            <span class="num w-11 text-right text-xs text-secondary">{{ formatNumber(p.share, 1) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!(tables?.top_products ?? []).length"><td colspan="6" class="px-4 py-6 text-secondary">No product sales in this period.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </Deferred>
            </ChartPanel>

            <!-- Recent transactions -->
            <ChartPanel title="Recent transactions" meta="Latest 10 in period" flush class="col-span-12">
                <Deferred data="tables">
                    <template #fallback><div class="p-4"><ChartSkeleton kind="rows" /></div></template>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-sunken text-left text-xs text-secondary">
                                <tr class="h-8">
                                    <th class="pl-4 font-medium">Reference</th>
                                    <th class="px-3 font-medium">Date</th>
                                    <th class="px-3 font-medium">Branch</th>
                                    <th class="hidden px-3 font-medium md:table-cell">Channel</th>
                                    <th class="hidden px-3 text-right font-medium sm:table-cell">Items</th>
                                    <th class="px-3 text-right font-medium">Amount</th>
                                    <th class="pr-4 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="t in tables?.recent_transactions ?? []" :key="t.id" class="h-9 hover:bg-surface-hover">
                                    <td class="pl-4 font-mono text-xs whitespace-nowrap text-ink">{{ t.reference }}</td>
                                    <td class="px-3 text-xs whitespace-nowrap text-secondary">{{ formatDate(t.occurred_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</td>
                                    <td class="px-3 whitespace-nowrap">{{ t.branch }}</td>
                                    <td class="hidden px-3 text-secondary capitalize md:table-cell">{{ t.channel }}</td>
                                    <td class="num hidden px-3 text-right sm:table-cell">{{ t.items }}</td>
                                    <td class="num px-3 text-right text-ink">{{ money(t.amount) }}</td>
                                    <td class="pr-4"><Badge :tone="statusTone[t.status]" dot class="capitalize">{{ t.status }}</Badge></td>
                                </tr>
                                <tr v-if="!(tables?.recent_transactions ?? []).length"><td colspan="7" class="px-4 py-6 text-secondary">No transactions in this period.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </Deferred>
            </ChartPanel>
        </div>
    </AppLayout>
</template>
