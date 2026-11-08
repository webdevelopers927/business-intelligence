<script setup lang="ts">
import { computed } from 'vue';
import { cn, initials } from '@/lib/utils';

/**
 * Initials on a neutral tint derived from the name. Deliberately NOT the
 * categorical chart palette — chart colors are reserved for data.
 */
const props = withDefaults(defineProps<{ name: string; size?: 'sm' | 'md'; square?: boolean; class?: string }>(), {
    size: 'md',
    square: false,
});

const tints = ['#E4E7EE', '#E6E9F3', '#E9E6EE', '#E3EAE8', '#ECE8E2'];
const darkTints = ['#2A3243', '#283047', '#30293F', '#25332F', '#352F28'];

const index = computed(() => [...props.name].reduce((sum, ch) => sum + ch.charCodeAt(0), 0) % tints.length);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center font-semibold text-secondary select-none',
                'bg-[var(--avatar-tint)] dark:bg-[var(--avatar-tint-dark)]',
                size === 'sm' ? 'size-6 text-[10px]' : 'size-7 text-[11px]',
                square ? 'rounded-md' : 'rounded-full',
                props.class,
            )
        "
        :style="{ '--avatar-tint': tints[index], '--avatar-tint-dark': darkTints[index] }"
        aria-hidden="true"
    >
        {{ initials(name) }}
    </span>
</template>
