<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OrbitMark from '@/components/shell/OrbitMark.vue';
import Toaster from '@/components/ui/Toaster.vue';

/**
 * Auth & onboarding frame: one narrow column, the mark, a 20px title and a
 * bordered form panel. No illustrations or marketing copy — signing in is a task.
 */
withDefaults(defineProps<{ title: string; description?: string; width?: 'sm' | 'md' }>(), { width: 'sm' });
</script>

<template>
    <Head :title="title" />
    <div class="flex min-h-dvh flex-col bg-bg">
        <header class="flex h-14 items-center px-6">
            <Link href="/" aria-label="ORBIT home"><OrbitMark wordmark /></Link>
        </header>

        <main class="flex flex-1 items-start justify-center px-4 pt-[8vh] pb-16">
            <div :class="['w-full', width === 'sm' ? 'max-w-[380px]' : 'max-w-[520px]']">
                <div class="mb-6">
                    <h1 class="text-lg font-semibold tracking-[-0.01em] text-ink">{{ title }}</h1>
                    <p v-if="description" class="mt-1 text-base text-secondary">{{ description }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-6">
                    <slot />
                </div>
                <div v-if="$slots.footer" class="mt-5 text-center text-sm text-secondary">
                    <slot name="footer" />
                </div>
            </div>
        </main>
    </div>
    <Toaster />
</template>
