<script setup lang="ts">
/**
 * Two-column settings pattern (DESIGN_SYSTEM §6, archetype 4): title and
 * description on the left at ≥1024px, controls on the right; sections are
 * divided by rules rather than wrapped in cards.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <section class="grid gap-x-10 gap-y-4 border-t border-border py-6 first:border-t-0 first:pt-0 lg:grid-cols-[240px_1fr]">
        <div>
            <h2 class="text-sm font-semibold text-ink">{{ title }}</h2>
            <p v-if="description" class="mt-1 text-sm text-secondary">{{ description }}</p>
        </div>
        <div class="min-w-0">
            <slot />
        </div>
    </section>
</template>
