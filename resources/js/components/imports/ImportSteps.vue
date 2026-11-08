<script setup lang="ts">
import { Check } from 'lucide-vue-next';
import { cn } from '@/lib/utils';

/** Upload → Map columns → Process: where the user is in the import flow. */
defineProps<{ current: 1 | 2 | 3 }>();
const steps = ['Upload file', 'Map columns', 'Process'];
</script>

<template>
    <ol class="flex items-center gap-2 text-xs" aria-label="Import progress">
        <li v-for="(step, i) in steps" :key="step" class="flex items-center gap-2">
            <span
                :class="
                    cn(
                        'num flex size-5 items-center justify-center rounded-full border text-[11px] font-semibold',
                        i + 1 < current && 'border-positive bg-positive text-white',
                        i + 1 === current && 'border-accent text-accent-ink',
                        i + 1 > current && 'border-border-strong text-muted',
                    )
                "
                :aria-current="i + 1 === current ? 'step' : undefined"
            >
                <Check v-if="i + 1 < current" class="size-3" :stroke-width="3" aria-label="Done" />
                <template v-else>{{ i + 1 }}</template>
            </span>
            <span :class="i + 1 === current ? 'font-medium text-ink' : 'text-secondary'">{{ step }}</span>
            <span v-if="i < steps.length - 1" class="mx-1 h-px w-8 bg-border-strong" aria-hidden="true" />
        </li>
    </ol>
</template>
