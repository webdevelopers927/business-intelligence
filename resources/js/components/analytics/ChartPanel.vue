<script setup lang="ts">
import { Ellipsis, Table2 } from 'lucide-vue-next';
import { ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import { cn } from '@/lib/utils';

/**
 * Panel for an object (chart or table): 44px header with title, instrument
 * metadata and an overflow menu. Charts offer "View as table" — the
 * accessible equivalent of the canvas (DESIGN_SYSTEM §8).
 */
withDefaults(defineProps<{ title: string; meta?: string; hasTable?: boolean; class?: string; flush?: boolean }>(), { hasTable: false, flush: false });
const showTable = ref(false);
</script>

<template>
    <section :class="cn('flex min-w-0 flex-col rounded-lg border border-border bg-surface', $props.class)" :aria-label="title">
        <header class="flex h-11 shrink-0 items-center justify-between gap-3 border-b border-border px-4">
            <div class="flex min-w-0 items-baseline gap-3">
                <h2 class="truncate text-sm font-semibold text-ink">{{ title }}</h2>
                <slot name="legend" />
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span v-if="meta" class="label-instrument hidden sm:inline">{{ meta }}</span>
                <slot name="actions" />
                <Menu v-if="hasTable" width="w-44">
                    <template #trigger>
                        <Button variant="ghost" size="icon-sm" :aria-label="`${title} options`"><Ellipsis /></Button>
                    </template>
                    <MenuItem @select="showTable = !showTable"><Table2 />{{ showTable ? 'View as chart' : 'View as table' }}</MenuItem>
                </Menu>
            </div>
        </header>
        <div :class="cn('flex min-h-0 flex-1 flex-col', !flush && 'p-4')">
            <slot v-if="showTable && $slots.table" name="table" />
            <slot v-else />
        </div>
    </section>
</template>
