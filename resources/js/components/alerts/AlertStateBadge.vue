<script setup lang="ts">
import Badge from '@/components/ui/Badge.vue';
import type { AlertState } from '@/types/alerts';

/** Dot + word, never color alone (DESIGN_SYSTEM §7). */
defineProps<{ state: AlertState; active: boolean; evaluated: boolean }>();
</script>

<template>
    <Badge v-if="!active" tone="neutral" dot>Paused</Badge>
    <Badge v-else-if="state === 'triggered'" tone="negative" dot>Triggered</Badge>
    <Badge v-else-if="!evaluated" tone="accent" dot>Pending first check</Badge>
    <Badge v-else tone="positive" dot>OK</Badge>
</template>
