<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/domain';

/**
 * Native <select>: fully keyboard/screen-reader accessible and mobile-native.
 * Used for forms; richer comboboxes are reserved for filter bars.
 */
defineOptions({ inheritAttrs: false });

const model = defineModel<string | number | null>();
defineProps<{ options: Option[]; placeholder?: string; invalid?: boolean; class?: string }>();

// A null/undefined model selects the "" option (e.g. "All types" in filters),
// so the control never renders blank.
const selected = computed({
    get: () => model.value ?? '',
    set: (value) => (model.value = value),
});
</script>

<template>
    <div :class="cn('relative', $props.class)">
        <select
            v-model="selected"
            v-bind="$attrs"
            :aria-invalid="invalid || undefined"
            class="h-8 w-full appearance-none rounded-md border border-border-strong bg-surface pr-8 pl-2.5 text-sm text-ink transition-[border-color,box-shadow] duration-[120ms] focus-visible:border-accent disabled:cursor-not-allowed disabled:bg-surface-sunken disabled:text-muted aria-invalid:border-negative"
        >
            <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>
        <ChevronDown class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
    </div>
</template>
