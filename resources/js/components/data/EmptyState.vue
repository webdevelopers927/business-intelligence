<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Inline, left-aligned empty state: 14px title, one-line explanation, one
 * action. Distinguish "nothing yet" (call to create/import) from "no results
 * for these filters" (call to reset) — the caller supplies the right copy.
 */
defineProps<{ title: string; description?: string; icon?: Component }>();
</script>

<template>
    <div class="flex items-start gap-3">
        <component :is="icon" v-if="icon" class="mt-0.5 size-5 shrink-0 text-muted" :stroke-width="1.5" aria-hidden="true" />
        <div>
            <p class="text-sm font-semibold text-ink">{{ title }}</p>
            <p v-if="description" class="mt-0.5 max-w-prose text-sm text-secondary">{{ description }}</p>
            <div v-if="$slots.default" class="mt-3"><slot /></div>
        </div>
    </div>
</template>
