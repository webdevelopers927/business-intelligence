<script setup lang="ts">
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const model = defineModel<string | number | null>();
defineProps<{ invalid?: boolean; class?: string }>();
</script>

<template>
    <input
        v-model="model"
        v-bind="$attrs"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'h-8 w-full min-w-0 rounded-md border border-border-strong bg-surface px-2.5 text-sm text-ink transition-[border-color,box-shadow] duration-[120ms] placeholder:text-muted',
                'focus-visible:border-accent disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-muted',
                'aria-invalid:border-negative',
                $props.class,
            )
        "
    />
</template>
