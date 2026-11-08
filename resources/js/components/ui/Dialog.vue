<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { cn } from '@/lib/utils';

const open = defineModel<boolean>('open', { default: false });

withDefaults(defineProps<{ title: string; description?: string; size?: 'sm' | 'md'; class?: string }>(), { size: 'sm' });
</script>

<template>
    <DialogRoot v-model:open="open">
        <slot name="trigger" />
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-40 bg-[#0B1020]/40 data-[state=open]:animate-[fade-in_180ms_ease-out]" />
            <DialogContent
                :class="
                    cn(
                        'fixed top-[12vh] left-1/2 z-50 flex max-h-[76vh] w-[calc(100vw-32px)] -translate-x-1/2 flex-col rounded-lg border border-border bg-surface-raised shadow-overlay',
                        'data-[state=open]:animate-[dialog-in_180ms_ease-out] focus:outline-none',
                        size === 'sm' ? 'max-w-[480px]' : 'max-w-[560px]',
                        $props.class,
                    )
                "
            >
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-md font-semibold text-ink">{{ title }}</DialogTitle>
                        <DialogDescription v-if="description" class="text-sm text-secondary">{{ description }}</DialogDescription>
                    </div>
                    <DialogClose
                        class="-mr-1 inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-surface-hover hover:text-ink"
                        aria-label="Close"
                    >
                        <X class="size-4" />
                    </DialogClose>
                </header>
                <div class="overflow-y-auto px-5 py-4">
                    <slot />
                </div>
                <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-border px-5 py-3">
                    <slot name="footer" />
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style>
@keyframes fade-in {
    from {
        opacity: 0;
    }
}
@keyframes dialog-in {
    from {
        opacity: 0;
        /* Centering uses the `translate` property (Tailwind 4); animate `transform` only. */
        transform: translateY(4px);
    }
}
</style>
