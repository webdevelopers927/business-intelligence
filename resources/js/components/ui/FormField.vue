<script setup lang="ts">
import { CircleAlert } from 'lucide-vue-next';

/**
 * Label above control; required mark after the label; helper text below,
 * replaced by the error message when invalid (DESIGN_SYSTEM §7 FormField).
 */
defineProps<{
    id: string;
    label: string;
    error?: string | undefined;
    hint?: string;
    required?: boolean;
}>();
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex items-baseline justify-between gap-2">
            <label :for="id" class="text-sm font-medium text-ink">
                {{ label }}<span v-if="required" class="ml-0.5 text-negative" aria-hidden="true">*</span>
            </label>
            <slot name="label-end" />
        </div>
        <slot :id="id" :invalid="Boolean(error)" :described-by="error || hint ? `${id}-desc` : undefined" />
        <p v-if="error" :id="`${id}-desc`" class="flex items-start gap-1 text-xs text-negative" role="alert">
            <CircleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
        <p v-else-if="hint" :id="`${id}-desc`" class="text-xs text-secondary">{{ hint }}</p>
    </div>
</template>
