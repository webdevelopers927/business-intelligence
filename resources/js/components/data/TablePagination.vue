<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import { formatNumber } from '@/lib/format';
import type { PaginationMeta } from '@/types/table';

const props = withDefaults(defineProps<{ meta: PaginationMeta; perPageOptions?: number[] }>(), { perPageOptions: () => [25, 50, 100] });
const emit = defineEmits<{ page: [page: number, perPage?: number] }>();
</script>

<template>
    <div v-if="meta.total > 0" class="flex flex-wrap items-center justify-between gap-3 pt-3 text-xs text-secondary">
        <p class="num">
            {{ formatNumber(meta.from) }}–{{ formatNumber(meta.to) }} of {{ formatNumber(meta.total) }}
        </p>
        <div class="flex items-center gap-3">
            <label v-if="perPageOptions.length > 1" class="flex items-center gap-1.5">
                Rows
                <select
                    :value="meta.per_page"
                    class="h-7 rounded-md border border-border-strong bg-surface px-1.5 text-xs text-ink"
                    @change="emit('page', 1, Number(($event.target as HTMLSelectElement).value))"
                >
                    <option v-for="n in props.perPageOptions" :key="n" :value="n">{{ n }}</option>
                </select>
            </label>
            <div class="flex items-center gap-1">
                <Button size="icon-sm" variant="ghost" :disabled="meta.current_page <= 1" aria-label="Previous page" @click="emit('page', meta.current_page - 1)">
                    <ChevronLeft />
                </Button>
                <span class="num min-w-16 text-center">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button size="icon-sm" variant="ghost" :disabled="meta.current_page >= meta.last_page" aria-label="Next page" @click="emit('page', meta.current_page + 1)">
                    <ChevronRight />
                </Button>
            </div>
        </div>
    </div>
</template>
