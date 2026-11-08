<script setup lang="ts">
import { DropdownMenuItem } from 'reka-ui';
import { cn } from '@/lib/utils';

defineProps<{ destructive?: boolean; disabled?: boolean; class?: string }>();
const emit = defineEmits<{ select: [event: Event] }>();
</script>

<template>
    <DropdownMenuItem
        :disabled="disabled"
        :class="
            cn(
                'flex h-8 cursor-default items-center gap-2 rounded-sm px-2 text-sm text-ink outline-none select-none',
                'data-[highlighted]:bg-surface-hover data-[disabled]:pointer-events-none data-[disabled]:text-muted',
                '[&_svg]:size-4 [&_svg]:text-secondary',
                destructive && 'text-negative data-[highlighted]:bg-negative-subtle [&_svg]:text-negative',
                $props.class,
            )
        "
        @select="(e: Event) => emit('select', e)"
    >
        <slot />
    </DropdownMenuItem>
</template>
