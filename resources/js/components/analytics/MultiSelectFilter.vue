<script setup lang="ts">
import { Check, ChevronDown } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/domain';

/**
 * Dimension filter: checkbox list in a popover, applied on close/Apply so a
 * multi-pick triggers one reload, not one per click.
 */
const props = defineProps<{ label: string; allLabel: string; options: Option[]; modelValue: number[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: number[]] }>();

const open = ref(false);
const draft = ref<number[]>([...props.modelValue]);
watch(() => props.modelValue, (v) => (draft.value = [...v]));

const summary = computed(() => {
    if (props.modelValue.length === 0) return props.allLabel;
    if (props.modelValue.length === 1) return props.options.find((o) => Number(o.value) === props.modelValue[0])?.label ?? '1 selected';
    return `${props.modelValue.length} ${props.label.toLowerCase()}`;
});

function toggle(id: number) {
    draft.value = draft.value.includes(id) ? draft.value.filter((x) => x !== id) : [...draft.value, id];
}

function apply() {
    open.value = false;
    const same = draft.value.length === props.modelValue.length && draft.value.every((v) => props.modelValue.includes(v));
    if (!same) emit('update:modelValue', [...draft.value].sort((a, b) => a - b));
}
</script>

<template>
    <PopoverRoot v-model:open="open" @update:open="(o: boolean) => !o && apply()">
        <PopoverTrigger as-child>
            <button
                type="button"
                :class="
                    cn(
                        'inline-flex h-8 max-w-56 items-center gap-1.5 rounded-md border px-2.5 text-sm transition-colors duration-[120ms] hover:bg-surface-hover',
                        modelValue.length ? 'border-accent bg-accent-subtle text-accent-ink' : 'border-border-strong bg-surface text-ink',
                    )
                "
                :aria-label="`${label}: ${summary}`"
            >
                <span class="truncate">{{ summary }}</span>
                <ChevronDown class="size-3.5 shrink-0 opacity-60" aria-hidden="true" />
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent align="start" :side-offset="6" class="z-50 w-64 rounded-md border border-border bg-surface-raised p-1 shadow-overlay focus:outline-none">
                <p class="label-instrument px-2 pt-1.5 pb-1">{{ label }}</p>
                <div class="max-h-64 overflow-y-auto" role="listbox" aria-multiselectable="true">
                    <button
                        v-for="o in options"
                        :key="o.value"
                        type="button"
                        role="option"
                        :aria-selected="draft.includes(Number(o.value))"
                        class="flex h-8 w-full items-center gap-2 rounded-sm px-2 text-left text-sm text-ink hover:bg-surface-hover focus-visible:bg-surface-hover"
                        @click="toggle(Number(o.value))"
                    >
                        <span
                            :class="cn('flex size-4 items-center justify-center rounded-sm border', draft.includes(Number(o.value)) ? 'border-accent bg-accent text-white' : 'border-border-strong')"
                            aria-hidden="true"
                        >
                            <Check v-if="draft.includes(Number(o.value))" class="size-3" :stroke-width="3" />
                        </span>
                        <span class="truncate">{{ o.label }}</span>
                    </button>
                </div>
                <div class="mt-1 flex justify-between border-t border-border px-1 pt-1">
                    <Button variant="ghost" size="sm" :disabled="draft.length === 0" @click="draft = []">Clear</Button>
                    <Button variant="primary" size="sm" @click="apply">Apply</Button>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
