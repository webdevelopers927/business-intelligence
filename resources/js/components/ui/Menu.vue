<script setup lang="ts">
import { DropdownMenuContent, DropdownMenuPortal, DropdownMenuRoot, DropdownMenuTrigger } from 'reka-ui';
import { cn } from '@/lib/utils';

/** Dropdown menu shell; fill with MenuItem / MenuLabel / MenuSeparator. */
withDefaults(
    defineProps<{ align?: 'start' | 'center' | 'end'; side?: 'top' | 'bottom' | 'right' | 'left'; width?: string; class?: string }>(),
    { align: 'end', side: 'bottom', width: 'w-56' },
);
</script>

<template>
    <DropdownMenuRoot :modal="false">
        <DropdownMenuTrigger as-child>
            <slot name="trigger" />
        </DropdownMenuTrigger>
        <DropdownMenuPortal>
            <DropdownMenuContent
                :align="align"
                :side="side"
                :side-offset="6"
                :class="
                    cn(
                        'z-50 rounded-md border border-border bg-surface-raised p-1 shadow-overlay focus:outline-none',
                        'data-[state=open]:animate-[menu-in_140ms_ease-out]',
                        width,
                        $props.class,
                    )
                "
            >
                <slot />
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>

<style>
@keyframes menu-in {
    from {
        opacity: 0;
        transform: translateY(-2px);
    }
}
</style>
