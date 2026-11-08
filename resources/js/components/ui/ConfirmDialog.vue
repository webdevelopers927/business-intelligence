<script setup lang="ts">
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
} from 'reka-ui';
import Button from './Button.vue';

/**
 * Confirmation for destructive or irreversible actions. The title names the
 * object; the confirm label repeats the verb ("Remove member").
 */
const open = defineModel<boolean>('open', { default: false });

defineProps<{ title: string; description: string; confirmLabel: string; loading?: boolean }>();
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <AlertDialogRoot v-model:open="open">
        <AlertDialogPortal>
            <AlertDialogOverlay class="fixed inset-0 z-40 bg-[#0B1020]/40" />
            <AlertDialogContent
                class="fixed top-[20vh] left-1/2 z-50 w-[calc(100vw-32px)] max-w-[440px] -translate-x-1/2 rounded-lg border border-border bg-surface-raised p-5 shadow-overlay focus:outline-none"
            >
                <AlertDialogTitle class="text-md font-semibold text-ink">{{ title }}</AlertDialogTitle>
                <AlertDialogDescription class="mt-2 text-sm text-secondary">{{ description }}</AlertDialogDescription>
                <div class="mt-5 flex justify-end gap-2">
                    <AlertDialogCancel as-child>
                        <Button variant="secondary">Cancel</Button>
                    </AlertDialogCancel>
                    <AlertDialogAction as-child @click.prevent="emit('confirm')">
                        <Button variant="destructive" :loading="loading">{{ confirmLabel }}</Button>
                    </AlertDialogAction>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>
