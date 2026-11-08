<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import Tooltip from '@/components/ui/Tooltip.vue';
import { formatMetric, type Kpi, kpiValue } from '@/lib/metrics';
import DeltaBadge from './DeltaBadge.vue';

/**
 * The KPI readout: one bordered band divided by hairlines — not four floating
 * cards (DESIGN_SYSTEM §1 signature elements). No icons in KPI cells.
 */
defineProps<{ kpis: Kpi[]; comparisonLabel: string }>();
const currency = usePage().props.tenant?.currency ?? 'PKR';
const filterNames: Record<string, string> = { category: 'Product category', department: 'Department' };
</script>

<template>
    <dl class="grid grid-cols-2 overflow-hidden rounded-lg border border-border bg-surface lg:grid-cols-4">
        <div
            v-for="(kpi, i) in kpis"
            :key="kpi.key"
            :class="['px-5 py-4', i % 2 === 1 && 'border-l border-border', i >= 2 && 'border-t border-border lg:border-t-0', i === 2 && 'lg:border-l']"
        >
            <dt class="flex items-center gap-1.5">
                <span class="label-instrument">{{ kpi.label }}</span>
                <Tooltip :content="kpi.description">
                    <button type="button" class="text-muted hover:text-secondary" :aria-label="`About ${kpi.label}`"><Info class="size-3" /></button>
                </Tooltip>
            </dt>
            <dd class="num mt-1.5 truncate text-kpi font-semibold tracking-[-0.02em] text-ink" :title="formatMetric(kpi.value, kpi.format, currency)">
                {{ kpiValue(kpi, currency) }}
            </dd>
            <dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-secondary">
                <DeltaBadge :change="kpi.change" :polarity="kpi.polarity" :unit="kpi.change_unit" />
                <span>vs {{ comparisonLabel }}</span>
            </dd>
            <dd v-if="kpi.ignored_filters?.length" class="mt-1 text-xs text-muted">
                {{ kpi.ignored_filters.map((f) => filterNames[f] ?? f).join(', ') }} filter not applicable
            </dd>
        </div>
    </dl>
</template>
