<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Dot } from 'lucide-vue-next';

export interface Insight {
    kind: string;
    tone: 'positive' | 'negative' | 'neutral';
    title: string;
    detail: string;
}

/** Data-backed observations. Icon + tone + words, never color alone. */
defineProps<{ insights: Insight[] }>();
const icon = { positive: ArrowUpRight, negative: ArrowDownRight, neutral: Dot } as const;
const tone = { positive: 'text-positive bg-positive-subtle', negative: 'text-negative bg-negative-subtle', neutral: 'text-secondary bg-surface-sunken' } as const;
</script>

<template>
    <ul v-if="insights.length" class="divide-y divide-border">
        <li v-for="insight in insights" :key="insight.kind" class="flex gap-3 px-4 py-3">
            <span :class="['mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-sm', tone[insight.tone]]" aria-hidden="true">
                <component :is="icon[insight.tone]" class="size-3.5" :stroke-width="2.25" />
            </span>
            <div class="min-w-0">
                <p class="text-sm font-medium text-ink">{{ insight.title }}</p>
                <p class="mt-0.5 text-xs text-secondary">{{ insight.detail }}</p>
            </div>
        </li>
    </ul>
    <p v-else class="px-4 py-6 text-sm text-secondary">No notable changes for this period. Insights appear when a metric moves by 5% or more.</p>
</template>
