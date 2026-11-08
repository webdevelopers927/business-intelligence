<script setup lang="ts">
import { cn } from '@/lib/utils';

defineProps<{ class?: string }>();
</script>

<template>
    <div :class="cn('animate-pulse rounded-sm bg-surface-sunken motion-reduce:animate-none', $props.class)" aria-hidden="true" />
</template>
