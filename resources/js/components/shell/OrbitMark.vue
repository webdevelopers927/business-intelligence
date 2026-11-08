<script setup lang="ts">
/**
 * The ORBIT mark: a measurement point on a path — 1.5px-weight ring with a
 * filled dot at 45°. Logo, favicon and loading indicator; never decoration.
 */
withDefaults(defineProps<{ size?: number; spinning?: boolean; wordmark?: boolean }>(), {
    size: 20,
    spinning: false,
    wordmark: false,
});
</script>

<template>
    <span class="inline-flex items-center gap-2 text-ink">
        <svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" aria-hidden="true" class="shrink-0">
            <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.75" class="text-accent" />
            <g :class="spinning ? 'origin-center animate-spin [animation-duration:1.6s]' : ''">
                <circle cx="17.66" cy="6.34" r="3" fill="var(--accent)" stroke="var(--surface)" stroke-width="1.5" />
            </g>
        </svg>
        <span v-if="wordmark" class="text-[13px] font-semibold tracking-[0.18em]">ORBIT</span>
    </span>
</template>
