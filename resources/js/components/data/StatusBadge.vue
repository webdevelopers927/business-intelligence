<script setup lang="ts">
import { computed } from 'vue';
import Badge from '@/components/ui/Badge.vue';

/**
 * Status = dot + label, never color alone (DESIGN_SYSTEM §7). One mapping for
 * import and data source states so the same word always looks the same.
 */
const props = defineProps<{ status: string; label: string }>();

const tone = computed(() => {
    switch (props.status) {
        case 'completed':
        case 'active':
            return 'positive';
        case 'processing':
        case 'pending':
            return 'accent';
        case 'completed_with_errors':
            return 'warning';
        case 'failed':
        case 'error':
            return 'negative';
        default:
            return 'neutral';
    }
});
</script>

<template>
    <Badge :tone="tone" dot :class="status === 'processing' ? '[&>span:first-child]:animate-pulse' : ''">{{ label }}</Badge>
</template>
