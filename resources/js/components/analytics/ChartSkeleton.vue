<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue';

/** Placeholder with the final chart geometry: axis stubs + plot rect. */
withDefaults(defineProps<{ height?: number; kind?: 'chart' | 'rows' }>(), { height: 260, kind: 'chart' });
</script>

<template>
    <div v-if="kind === 'chart'" class="flex gap-3" :style="{ height: `${height}px` }" aria-busy="true" aria-label="Loading chart">
        <div class="flex w-10 flex-col justify-between py-1">
            <Skeleton v-for="i in 5" :key="i" class="h-2.5 w-8" />
        </div>
        <div class="flex flex-1 flex-col gap-2">
            <Skeleton class="flex-1 rounded-md" />
            <div class="flex justify-between"><Skeleton v-for="i in 6" :key="i" class="h-2.5 w-8" /></div>
        </div>
    </div>
    <div v-else class="flex flex-col divide-y divide-border" aria-busy="true" aria-label="Loading">
        <div v-for="i in 6" :key="i" class="flex items-center gap-3 py-2.5">
            <Skeleton class="h-3 w-1/3" /><Skeleton class="ml-auto h-3 w-14" /><Skeleton class="h-3 w-10" />
        </div>
    </div>
</template>
