<script setup lang="ts">
import { RotateCcw } from 'lucide-vue-next';
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import { useAnalyticsFilters } from '@/composables/useAnalyticsFilters';
import { formatDate } from '@/lib/format';
import type { AnalyticsFilterState, AnalyticsOptions, Period } from '@/types/analytics';
import DateRangeMenu from './DateRangeMenu.vue';
import MultiSelectFilter from './MultiSelectFilter.vue';

/** Sticky filter row: date range + comparison label, dimension filters, reset. */
const props = defineProps<{ filters: AnalyticsFilterState; options: AnalyticsOptions; period: Period }>();
const { state, apply, reset } = useAnalyticsFilters(props.filters);

const rangeLabel = computed(() => (props.filters.range === 'custom' ? props.period.current.label : `${props.period.preset_label} · ${props.period.current.label}`));
const isDefault = computed(() => props.filters.range === 'this_month' && !props.filters.branches.length && !props.filters.categories.length);
</script>

<template>
    <div class="flex min-h-12 flex-wrap items-center gap-2 py-2">
        <DateRangeMenu :presets="options.presets" :range="filters.range" :from="filters.from" :to="filters.to" :label="rangeLabel" @change="apply" />
        <span class="hidden text-xs text-secondary md:inline">vs {{ period.previous.label }}</span>
        <span class="mx-1 hidden h-5 w-px bg-border md:inline-block" aria-hidden="true" />
        <MultiSelectFilter
            label="Branches"
            all-label="All branches"
            :options="options.branches"
            :model-value="state.branches"
            @update:model-value="(v) => apply({ branches: v })"
        />
        <MultiSelectFilter
            label="Categories"
            all-label="All categories"
            :options="options.categories"
            :model-value="state.categories"
            @update:model-value="(v) => apply({ categories: v })"
        />
        <Button v-if="!isDefault" variant="ghost" size="sm" @click="reset"><RotateCcw />Reset</Button>
        <span v-if="period.data_through" class="ml-auto hidden text-xs text-muted lg:inline">Data through {{ formatDate(period.data_through) }}</span>
    </div>
</template>
