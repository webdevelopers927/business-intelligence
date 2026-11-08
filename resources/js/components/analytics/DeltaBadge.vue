<script setup lang="ts">
import { Minus, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { changeTone, formatPercentChange, type Polarity } from '@/lib/metrics';
import { cn } from '@/lib/utils';

/**
 * Arrow + signed change, colored by business polarity; "—" when not
 * computable. Ratios use percentage points ("+1.2 pts").
 */
const props = withDefaults(defineProps<{ change: number | null; polarity: Polarity; unit?: 'percent' | 'pts'; class?: string }>(), { unit: 'percent' });

const tone = computed(() => changeTone(props.change, props.polarity));
const icon = computed(() => (props.change === null || Math.abs(props.change) < 0.05 ? Minus : props.change > 0 ? TrendingUp : TrendingDown));
const text = computed(() => {
    if (props.unit !== 'pts' || props.change === null) return formatPercentChange(props.change);
    const sign = props.change > 0 ? '+' : props.change < 0 ? '−' : '';
    return `${sign}${formatNumber(Math.abs(props.change), 1)} pts`;
});
const toneClass = { positive: 'text-positive', negative: 'text-negative', neutral: 'text-secondary' } as const;
const srTone = { positive: 'favorable', negative: 'unfavorable', neutral: 'no significant change' } as const;
</script>

<template>
    <span
        :class="cn('num inline-flex items-center gap-1 text-xs font-medium', toneClass[tone], props.class)"
        :title="change === null ? 'No data in the comparison period' : undefined"
    >
        <component :is="icon" class="size-3" :stroke-width="2.25" aria-hidden="true" />
        {{ text }}
        <span class="sr-only">({{ change === null ? 'no comparison data' : srTone[tone] }})</span>
    </span>
</template>
