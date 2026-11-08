<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui';

/** Inverted, 12px, 400ms delay (DESIGN_SYSTEM §7). Provider lives in AppLayout. */
withDefaults(defineProps<{ content: string; side?: 'top' | 'right' | 'bottom' | 'left'; disabled?: boolean }>(), {
    side: 'top',
    disabled: false,
});
</script>

<template>
    <TooltipRoot :disabled="disabled">
        <TooltipTrigger as-child>
            <slot />
        </TooltipTrigger>
        <TooltipPortal>
            <TooltipContent
                :side="side"
                :side-offset="6"
                class="z-50 max-w-64 rounded-sm bg-ink px-2 py-1 text-xs text-surface shadow-overlay"
            >
                {{ content }}
            </TooltipContent>
        </TooltipPortal>
    </TooltipRoot>
</template>
