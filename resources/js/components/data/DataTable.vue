<script setup lang="ts" generic="Row extends { id: number | string }">
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-vue-next';
import Skeleton from '@/components/ui/Skeleton.vue';
import { cn } from '@/lib/utils';
import type { Column } from '@/types/table';

/**
 * Ledger table (DESIGN_SYSTEM §9): 32px sunken header, 36px rows, hairline
 * separators, right-aligned numbers, sortable headers with aria-sort,
 * skeleton rows while loading, and an empty slot spanning the body.
 *
 * Cells render `row[column.key]` unless a `#cell-<key>` slot is provided.
 */
const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: Row[];
        sort?: string | null;
        direction?: 'asc' | 'desc' | null;
        loading?: boolean;
        rowHref?: (row: Row) => string | undefined;
        caption?: string;
    }>(),
    { sort: null, direction: null, loading: false },
);

const emit = defineEmits<{ sort: [column: string] }>();

const hide = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' } as const;
const align = (c: Column) => (c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : 'text-left');
const ariaSort = (c: Column) =>
    props.sort === c.key ? (props.direction === 'asc' ? 'ascending' : 'descending') : c.sortable ? 'none' : undefined;
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="w-full text-sm">
            <caption v-if="caption" class="sr-only">{{ caption }}</caption>
            <thead class="sticky top-0 bg-surface-sunken">
                <tr class="h-8 text-xs text-secondary">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :aria-sort="ariaSort(column)"
                        :class="cn('px-3 font-medium whitespace-nowrap first:pl-4 last:pr-4', align(column), column.width, column.hideBelow && hide[column.hideBelow])"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            :class="cn('group inline-flex items-center gap-1 hover:text-ink', column.align === 'right' && 'flex-row-reverse')"
                            @click="emit('sort', column.key)"
                        >
                            {{ column.label }}
                            <ArrowUp v-if="sort === column.key && direction === 'asc'" class="size-3 text-ink" aria-hidden="true" />
                            <ArrowDown v-else-if="sort === column.key" class="size-3 text-ink" aria-hidden="true" />
                            <ChevronsUpDown v-else class="size-3 opacity-0 group-hover:opacity-100" aria-hidden="true" />
                        </button>
                        <span v-else>{{ column.label }}</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <template v-if="loading">
                    <tr v-for="i in 8" :key="`sk-${i}`" class="h-9">
                        <td v-for="column in columns" :key="column.key" :class="cn('px-3 first:pl-4 last:pr-4', column.hideBelow && hide[column.hideBelow])">
                            <Skeleton :class="cn('h-3', column.align === 'right' ? 'ml-auto w-12' : 'w-3/4')" />
                        </td>
                    </tr>
                </template>
                <tr v-else-if="rows.length === 0">
                    <td :colspan="columns.length" class="px-4 py-8">
                        <slot name="empty" />
                    </td>
                </tr>
                <template v-else>
                    <tr v-for="row in rows" :key="row.id" class="group h-9 hover:bg-surface-hover">
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="cn('px-3 whitespace-nowrap first:pl-4 last:pr-4', align(column), column.align === 'right' && 'num', column.hideBelow && hide[column.hideBelow])"
                        >
                            <slot :name="`cell-${column.key}`" :row="row" :value="(row as Record<string, unknown>)[column.key]">
                                {{ (row as Record<string, unknown>)[column.key] ?? '—' }}
                            </slot>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
