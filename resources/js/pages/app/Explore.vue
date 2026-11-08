<script setup lang="ts">
import { Deferred, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronRight, CornerLeftUp } from 'lucide-vue-next';
import { computed } from 'vue';
import ChartPanel from '@/components/analytics/ChartPanel.vue';
import ChartSkeleton from '@/components/analytics/ChartSkeleton.vue';
import DeltaBadge from '@/components/analytics/DeltaBadge.vue';
import KpiStrip from '@/components/analytics/KpiStrip.vue';
import EChart from '@/components/charts/EChart.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Button from '@/components/ui/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type ChartTheme, baseOption, seriesColor, tooltipHeader, tooltipRow } from '@/lib/chart-theme';
import { formatCompact, formatDate, formatNumber } from '@/lib/format';
import { formatMetric, type Kpi } from '@/lib/metrics';
import { route } from '@/lib/route';
import type { BreakdownRow, Series } from '@/types/analytics';
import type { PaginationMeta } from '@/types/table';

type Level = 'months' | 'branches' | 'categories' | 'transactions';
interface Crumb {
    label: string;
    params: Record<string, string | number>;
}
interface TxRow {
    id: number;
    reference: string;
    occurred_at: string;
    channel: string;
    sku: string;
    product: string;
    quantity: number;
    unit_price: number;
    discount: number;
    amount: number;
}

const props = defineProps<{
    level: Level;
    crumbs: Crumb[];
    context: { month: string | null; branch: number | null; category: number | null; range_label: string; comparison_label: string };
    totals: Record<string, Kpi>;
    data?: Series | BreakdownRow[] | { rows: TxRow[]; meta: PaginationMeta };
}>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const money = (v: number | null | undefined) => formatMetric(v, 'currency', currency.value);

const kpis = computed(() => Object.values(props.totals));
const series = computed(() => (props.level === 'months' ? (props.data as Series | undefined) : undefined));
const rows = computed(() => (props.level === 'branches' || props.level === 'categories' ? ((props.data as BreakdownRow[] | undefined) ?? []) : []));
const tx = computed(() => (props.level === 'transactions' ? (props.data as { rows: TxRow[]; meta: PaginationMeta } | undefined) : undefined));

const heading = computed(
    () =>
        ({
            months: 'Revenue by month',
            branches: 'Revenue by branch',
            categories: 'Revenue by category',
            transactions: 'Transaction lines',
        })[props.level],
);
const nextHint = computed(
    () =>
        ({
            months: 'Select a month to see its branches.',
            branches: 'Select a branch to see its categories.',
            categories: 'Select a category to see its transactions.',
            transactions: '',
        })[props.level],
);

function go(params: Record<string, string | number>) {
    router.visit(route('explore', params), { preserveScroll: false });
}

function drill(index: number) {
    if (props.level === 'months' && series.value) {
        go({ month: series.value.keys[index]! });
    } else if (props.level === 'branches') {
        const row = rows.value[index];
        if (row) go({ month: props.context.month!, branch: row.id });
    } else if (props.level === 'categories') {
        const row = rows.value[index];
        if (row) go({ month: props.context.month!, branch: props.context.branch!, category: row.id });
    }
}

function monthsOption(theme: ChartTheme) {
    const s = series.value!;
    const base = baseOption(theme);
    return {
        ...base,
        tooltip: {
            ...base.tooltip,
            trigger: 'axis',
            axisPointer: { type: 'shadow', shadowStyle: { color: theme.dark ? 'rgba(255,255,255,0.04)' : 'rgba(16,24,40,0.04)' } },
            formatter: (items: { dataIndex: number }[]) => {
                const i = items[0]?.dataIndex ?? 0;
                return tooltipHeader(s.labels[i] ?? '') + tooltipRow(theme.series[0]!, 'Revenue', money(s.series.revenue?.[i]), true) +
                    tooltipRow(theme.muted, 'Orders', formatNumber(s.series.orders?.[i])) +
                    '<div style="margin-top:4px;font-size:11px;color:var(--text-secondary)">Click to break down by branch</div>';
            },
        },
        xAxis: { ...base.categoryAxis, type: 'category', data: s.labels.map((l) => l.replace(/ (\d{2})(\d{2})$/, " '$2")) },
        yAxis: { ...base.valueAxis, type: 'value', axisLabel: { ...base.valueAxis.axisLabel, formatter: (v: number) => formatCompact(v) } },
        series: [{ type: 'bar', cursor: 'pointer', data: s.series.revenue, barMaxWidth: 28, itemStyle: { color: theme.series[0], borderRadius: [4, 4, 0, 0] } }],
    };
}

function breakdownOption(theme: ChartTheme) {
    const data = [...rows.value].reverse();
    const base = baseOption(theme);
    const nextLevel = props.level === 'branches' ? 'category' : 'transactions';
    return {
        ...base,
        grid: { ...base.grid, right: 64 },
        tooltip: {
            ...base.tooltip,
            trigger: 'item',
            formatter: (p: { dataIndex: number }) => {
                const r = data[p.dataIndex]!;
                return tooltipHeader(r.label) + tooltipRow(seriesColor(theme, r.color_index), 'Revenue', money(r.values.revenue), true) +
                    tooltipRow(theme.muted, 'Previous month', money(r.previous?.revenue)) +
                    `<div style="margin-top:4px;font-size:11px;color:var(--text-secondary)">Click to see ${nextLevel === 'category' ? 'categories' : 'transactions'}</div>`;
            },
        },
        xAxis: { ...base.valueAxis, type: 'value', axisLabel: { show: false }, splitLine: { show: false } },
        yAxis: { ...base.categoryAxis, type: 'category', data: data.map((r) => r.label), axisLine: { show: false }, axisLabel: { ...base.categoryAxis.axisLabel, color: theme.secondary } },
        series: [{
            type: 'bar',
            cursor: 'pointer',
            barMaxWidth: 22,
            data: data.map((r) => ({ value: r.values.revenue, itemStyle: { color: seriesColor(theme, r.color_index), borderRadius: [0, 4, 4, 0] } })),
            label: { show: true, position: 'right', color: theme.text, fontSize: 11, formatter: (p: { value: number }) => formatCompact(p.value) },
        }],
    };
}

const change = (r: BreakdownRow) => (r.previous?.revenue ? (((r.values.revenue ?? 0) - r.previous.revenue) / Math.abs(r.previous.revenue)) * 100 : null);

function txPage(page: number) {
    router.get(window.location.pathname, { ...props.crumbs.at(-1)!.params, page }, { only: ['data'], preserveState: true, preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Explore" :breadcrumbs="[{ label: 'Executive overview', href: route('dashboard') }]">
        <template #filters>
            <nav class="flex min-h-12 flex-wrap items-center gap-1 py-2 text-sm" aria-label="Drill-down path">
                <template v-for="(c, i) in crumbs" :key="i">
                    <ChevronRight v-if="i > 0" class="size-3.5 text-muted" aria-hidden="true" />
                    <Link
                        v-if="i < crumbs.length - 1"
                        :href="route('explore', c.params)"
                        class="rounded-sm px-1.5 py-0.5 text-secondary hover:bg-surface-hover hover:text-ink"
                    >{{ c.label }}</Link>
                    <span v-else class="rounded-sm bg-accent-subtle px-1.5 py-0.5 font-medium text-accent-ink" aria-current="page">{{ c.label }}</span>
                </template>
                <Button v-if="crumbs.length > 1" variant="ghost" size="sm" class="ml-auto" :href="route('explore', crumbs[crumbs.length - 2]!.params)">
                    <CornerLeftUp />Up one level
                </Button>
            </nav>
        </template>

        <KpiStrip :kpis="kpis" :comparison-label="context.comparison_label" />

        <ChartPanel :title="heading" :meta="`${currency} · ${context.range_label}`" class="mt-6" :flush="level === 'transactions'">
            <Deferred data="data">
                <template #fallback><div :class="level === 'transactions' ? 'p-4' : ''"><ChartSkeleton :kind="level === 'transactions' ? 'rows' : 'chart'" /></div></template>

                <!-- Months -->
                <template v-if="level === 'months' && series">
                    <EChart :option="monthsOption" :height="300" aria-label="Revenue by month for the last 12 months" @select="(p) => drill(p.dataIndex)" />
                    <p class="mt-1 text-xs text-muted">{{ nextHint }}</p>
                </template>

                <!-- Branches / categories -->
                <div v-else-if="level === 'branches' || level === 'categories'" class="grid gap-6 lg:grid-cols-[1fr_1fr]">
                    <div>
                        <EChart
                            v-if="rows.length"
                            :option="breakdownOption"
                            :height="Math.max(140, rows.length * 38)"
                            :aria-label="heading"
                            @select="(p) => drill(rows.length - 1 - p.dataIndex)"
                        />
                        <p v-if="rows.length" class="mt-1 text-xs text-muted">{{ nextHint }}</p>
                        <p v-else class="py-8 text-sm text-secondary">No completed sales recorded at this level.</p>
                    </div>
                    <table v-if="rows.length" class="w-full self-start text-sm">
                        <thead class="text-left text-xs text-secondary">
                            <tr class="h-8 border-b border-border">
                                <th class="font-medium">{{ level === 'branches' ? 'Branch' : 'Category' }}</th>
                                <th class="text-right font-medium">Revenue</th>
                                <th class="text-right font-medium">vs prev. month</th>
                                <th class="text-right font-medium">Gross margin</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(r, i) in rows" :key="r.id" class="h-9 cursor-pointer hover:bg-surface-hover" @click="drill(i)">
                                <td>
                                    <button type="button" class="flex items-center gap-2 text-left text-ink hover:text-accent-ink" @click.stop="drill(i)">
                                        <span class="size-2 rounded-[2px]" :style="{ background: `var(--series-${(r.color_index ?? 7) + 1}, var(--series-other))` }" aria-hidden="true" />
                                        {{ r.label }}
                                    </button>
                                </td>
                                <td class="num text-right text-ink">{{ money(r.values.revenue) }}</td>
                                <td class="text-right"><DeltaBadge :change="change(r)" polarity="up_good" /></td>
                                <td class="num text-right text-secondary">{{ formatNumber(r.values.gross_margin, 1) }}%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Transactions -->
                <template v-else-if="level === 'transactions' && tx">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-surface-sunken text-left text-xs text-secondary">
                                <tr class="h-8">
                                    <th class="pl-4 font-medium">Reference</th>
                                    <th class="px-3 font-medium">Date</th>
                                    <th class="px-3 font-medium">Product</th>
                                    <th class="hidden px-3 font-medium md:table-cell">Channel</th>
                                    <th class="px-3 text-right font-medium">Qty</th>
                                    <th class="hidden px-3 text-right font-medium sm:table-cell">Unit price</th>
                                    <th class="hidden px-3 text-right font-medium sm:table-cell">Discount</th>
                                    <th class="pr-4 text-right font-medium">Net amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="r in tx.rows" :key="r.id" class="h-9 hover:bg-surface-hover">
                                    <td class="pl-4 font-mono text-xs whitespace-nowrap text-ink">{{ r.reference }}</td>
                                    <td class="px-3 text-xs whitespace-nowrap text-secondary">{{ formatDate(r.occurred_at, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) }}</td>
                                    <td class="max-w-72 px-3"><span class="block truncate">{{ r.product }}</span><span class="font-mono text-[11px] text-muted">{{ r.sku }}</span></td>
                                    <td class="hidden px-3 text-secondary capitalize md:table-cell">{{ r.channel }}</td>
                                    <td class="num px-3 text-right">{{ formatNumber(r.quantity) }}</td>
                                    <td class="num hidden px-3 text-right text-secondary sm:table-cell">{{ money(r.unit_price) }}</td>
                                    <td class="num hidden px-3 text-right text-secondary sm:table-cell">{{ r.discount ? money(r.discount) : '—' }}</td>
                                    <td class="num pr-4 text-right text-ink">{{ money(r.amount) }}</td>
                                </tr>
                                <tr v-if="!tx.rows.length"><td colspan="8" class="px-4 py-6 text-secondary">No completed transaction lines at this level.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-4 pb-3"><TablePagination :meta="tx.meta" :per-page-options="[25]" @page="txPage" /></div>
                </template>
            </Deferred>
        </ChartPanel>
    </AppLayout>
</template>
