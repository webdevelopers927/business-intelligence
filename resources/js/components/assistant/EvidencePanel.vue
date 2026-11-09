<script setup lang="ts">
import { ChevronDown, Database } from 'lucide-vue-next';
import { ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { formatNumber } from '@/lib/format';
import { formatMetric, type MetricFormat } from '@/lib/metrics';

export interface Evidence {
    id: number;
    tool: string;
    title: string;
    period: string;
    filters: string[];
    estimate: boolean;
    currency: string;
    columns: { key: string; label: string; format?: string }[];
    rows: Record<string, unknown>[];
}

/**
 * The figures behind an answer, rendered from the tool results the model
 * received — not from its prose (AI-2). Forecast items are marked Estimate.
 */
defineProps<{ items: Evidence[] }>();
const open = ref(false);

function cell(e: Evidence, col: Evidence['columns'][number], row: Record<string, unknown>): string {
    const v = row[col.key];
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v !== 'number') return String(v);
    const rowFormat = (row.format as MetricFormat | undefined) ?? 'number';
    switch (col.format) {
        case 'row':
            return formatMetric(v, rowFormat, e.currency, Math.abs(v) >= 100_000);
        case 'currency':
        case 'number':
        case 'percent':
            return formatMetric(v, col.format, e.currency, col.format !== 'percent' && Math.abs(v) >= 100_000);
        case 'share':
            return `${formatNumber(v, 1)}%`;
        case 'pts':
            return `${v > 0 ? '+' : ''}${formatNumber(v, 1)} pts`;
        case 'change':
            return `${v > 0 ? '+' : ''}${formatNumber(v, 1)}${row.change_unit === 'pts' ? ' pts' : '%'}`;
        default:
            return formatNumber(v, Number.isInteger(v) ? 0 : 2);
    }
}

const numeric = (col: Evidence['columns'][number]) => col.format !== undefined;
</script>

<template>
    <div v-if="items.length" class="mt-3 rounded-md border border-border bg-surface-sunken/60">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs font-medium text-secondary hover:text-ink"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span class="flex items-center gap-1.5"><Database class="size-3.5" aria-hidden="true" />Evidence · {{ items.length }} {{ items.length === 1 ? 'query' : 'queries' }}</span>
            <ChevronDown :class="['size-3.5 transition-transform', open && 'rotate-180']" aria-hidden="true" />
        </button>
        <div v-if="open" class="flex flex-col gap-3 border-t border-border p-3">
            <section v-for="e in items" :key="e.id" class="rounded-md border border-border bg-surface" :aria-label="e.title">
                <header class="flex flex-wrap items-center gap-2 border-b border-border px-3 py-2">
                    <span class="text-xs font-semibold text-ink">{{ e.title }}</span>
                    <Badge v-if="e.estimate" tone="accent">Estimate</Badge>
                    <span class="text-[11px] text-secondary">{{ e.period }}<template v-if="e.filters.length"> · {{ e.filters.join(', ') }}</template></span>
                </header>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-secondary">
                            <tr class="h-7">
                                <th v-for="c in e.columns" :key="c.key" :class="['px-3 font-medium whitespace-nowrap', numeric(c) ? 'text-right' : 'text-left']">{{ c.label }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(row, i) in e.rows" :key="i" class="h-7">
                                <td v-for="c in e.columns" :key="c.key" :class="['px-3 whitespace-nowrap', numeric(c) ? 'num text-right text-ink' : 'text-ink']">{{ cell(e, c, row) }}</td>
                            </tr>
                            <tr v-if="!e.rows.length">
                                <td :colspan="e.columns.length" class="px-3 py-2 text-secondary">No rows returned.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
