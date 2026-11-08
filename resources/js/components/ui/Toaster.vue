<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, X } from 'lucide-vue-next';
import { watch } from 'vue';
import { useToasts } from '@/composables/useToasts';

/** Renders server-flashed toasts (flash.toast) plus client-pushed ones. */
const page = usePage();
const { toasts, push, dismiss } = useToasts();

watch(
    () => page.props.flash.toast,
    (toast) => {
        if (toast) {
            push(toast);
        }
    },
    { immediate: true },
);

const icons = { success: CircleCheck, error: CircleAlert, info: Info } as const;
const iconTone = { success: 'text-positive', error: 'text-negative', info: 'text-accent' } as const;
</script>

<template>
    <div class="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[min(360px,calc(100vw-32px))] flex-col gap-2" aria-live="polite" role="status">
        <TransitionGroup
            enter-from-class="opacity-0 translate-y-1"
            enter-active-class="transition duration-[180ms] ease-out"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-[120ms]"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto flex items-start gap-2.5 rounded-lg border border-border bg-surface-raised px-3.5 py-3 shadow-overlay"
            >
                <component :is="icons[toast.type]" :class="['mt-0.5 size-4 shrink-0', iconTone[toast.type]]" aria-hidden="true" />
                <p class="flex-1 text-sm text-ink">{{ toast.message }}</p>
                <button
                    type="button"
                    class="-mr-1 inline-flex size-6 items-center justify-center rounded-sm text-muted hover:bg-surface-hover hover:text-ink"
                    aria-label="Dismiss notification"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
