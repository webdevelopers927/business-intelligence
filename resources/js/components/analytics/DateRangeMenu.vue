<script setup lang="ts">
import { CalendarDays, Check } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';
import Input from '@/components/ui/Input.vue';
import type { Option } from '@/types/domain';

/**
 * Preset rows with a check on the active one; custom range behind a hairline
 * in the footer (DESIGN_SYSTEM §6 FilterBar, dataviz filter controls).
 */
const props = defineProps<{ presets: Option[]; range: string; from: string; to: string; label: string }>();
const emit = defineEmits<{ change: [patch: { range: string; from?: string; to?: string }] }>();

const open = ref(false);
const from = ref(props.from);
const to = ref(props.to);
watch(() => [props.from, props.to], () => {
    from.value = props.from;
    to.value = props.to;
});

function pick(value: string) {
    open.value = false;
    if (value !== props.range) emit('change', { range: value });
}

function applyCustom() {
    if (!from.value || !to.value) return;
    open.value = false;
    emit('change', { range: 'custom', from: from.value, to: to.value });
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex h-8 items-center gap-2 rounded-md border border-border-strong bg-surface px-2.5 text-sm text-ink hover:bg-surface-hover"
                :aria-label="`Date range: ${label}`"
            >
                <CalendarDays class="size-4 text-secondary" aria-hidden="true" />
                <span class="font-medium">{{ label }}</span>
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent align="start" :side-offset="6" class="z-50 w-64 rounded-md border border-border bg-surface-raised p-1 shadow-overlay focus:outline-none">
                <ul role="listbox" aria-label="Date presets">
                    <li v-for="p in presets.filter((p) => p.value !== 'custom')" :key="p.value">
                        <button
                            type="button"
                            role="option"
                            :aria-selected="range === p.value"
                            class="flex h-8 w-full items-center justify-between rounded-sm px-2 text-sm text-ink hover:bg-surface-hover"
                            @click="pick(p.value)"
                        >
                            {{ p.label }}
                            <Check v-if="range === p.value" class="size-4 text-accent" :stroke-width="2.5" aria-hidden="true" />
                        </button>
                    </li>
                </ul>
                <form class="mt-1 border-t border-border p-2" @submit.prevent="applyCustom">
                    <p class="label-instrument mb-2">Custom range</p>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-xs text-secondary">From<Input v-model="from" type="date" class="mt-1 h-7 text-xs" :max="to" /></label>
                        <label class="text-xs text-secondary">To<Input v-model="to" type="date" class="mt-1 h-7 text-xs" :min="from" /></label>
                    </div>
                    <Button type="submit" size="sm" variant="secondary" class="mt-2 w-full">Apply range</Button>
                </form>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
