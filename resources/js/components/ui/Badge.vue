<script setup lang="ts">
import { cn } from '@/lib/utils';

/**
 * 20px, radius 4. `dot` adds the 6px status dot — status is never conveyed
 * by color alone, the label always accompanies it.
 */
const props = withDefaults(
    defineProps<{
        tone?: 'neutral' | 'accent' | 'positive' | 'negative' | 'warning';
        dot?: boolean;
        class?: string;
    }>(),
    { tone: 'neutral', dot: false },
);

const tones: Record<NonNullable<typeof props.tone>, string> = {
    neutral: 'bg-surface-sunken text-secondary',
    accent: 'bg-accent-subtle text-accent-ink',
    positive: 'bg-positive-subtle text-positive',
    negative: 'bg-negative-subtle text-negative',
    warning: 'bg-warning-subtle text-warning',
};
</script>

<template>
    <span :class="cn('inline-flex h-5 items-center gap-1.5 rounded-sm px-1.5 text-xs font-medium whitespace-nowrap', tones[tone], props.class)">
        <span v-if="dot" class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        <slot />
    </span>
</template>
