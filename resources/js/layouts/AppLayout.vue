<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { useMediaQuery, useStorage } from '@vueuse/core';
import { ChevronRight, Menu as MenuIcon } from 'lucide-vue-next';
import { TooltipProvider } from 'reka-ui';
import { computed, onBeforeUnmount, ref } from 'vue';
import AppSidebar from '@/components/shell/AppSidebar.vue';
import NotificationBell from '@/components/shell/NotificationBell.vue';
import Toaster from '@/components/ui/Toaster.vue';

export interface Breadcrumb {
    label: string;
    href?: string;
}

/**
 * Application shell (DESIGN_SYSTEM §6): sidebar 232/56px, 56px top bar with
 * breadcrumbs + title + contextual actions, optional sticky filter bar,
 * content with 24px gutters and a 1600px max width.
 */
const props = withDefaults(defineProps<{ title: string; breadcrumbs?: Breadcrumb[]; width?: 'full' | 'settings' | 'form' }>(), {
    breadcrumbs: () => [],
    width: 'full',
});

const isDesktop = useMediaQuery('(min-width: 768px)');
const isNarrowDesktop = useMediaQuery('(max-width: 1279px)');
const userCollapsed = useStorage<boolean | null>('orbit.sidebar.collapsed', null);
const collapsed = computed(() => userCollapsed.value ?? isNarrowDesktop.value);
const mobileOpen = ref(false);

const stopListening = router.on('navigate', () => {
    mobileOpen.value = false;
});
onBeforeUnmount(stopListening);

const contentWidth = computed(() => ({ full: 'max-w-[1600px]', settings: 'max-w-[880px]', form: 'max-w-[640px]' })[props.width]);
</script>

<template>
    <Head :title="title" />
    <TooltipProvider :delay-duration="400">
        <div class="flex h-dvh overflow-hidden bg-bg">
            <a href="#main" class="sr-only z-50 rounded-md bg-surface px-3 py-2 focus:not-sr-only focus:fixed focus:top-2 focus:left-2">Skip to content</a>

            <!-- Desktop sidebar -->
            <div v-if="isDesktop" class="h-full shrink-0">
                <AppSidebar :collapsed="collapsed" @toggle="userCollapsed = !collapsed" />
            </div>

            <!-- Mobile off-canvas sidebar -->
            <template v-else>
                <Transition enter-from-class="opacity-0" enter-active-class="transition-opacity duration-[180ms]" leave-to-class="opacity-0" leave-active-class="transition-opacity duration-[120ms]">
                    <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-[#0B1020]/40" @click="mobileOpen = false" />
                </Transition>
                <div
                    class="fixed inset-y-0 left-0 z-50 transition-transform duration-[180ms]"
                    :class="mobileOpen ? 'translate-x-0' : '-translate-x-full'"
                    :inert="!mobileOpen || undefined"
                >
                    <AppSidebar :collapsed="false" @toggle="mobileOpen = false" />
                </div>
            </template>

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="flex h-14 shrink-0 items-center gap-3 border-b border-border bg-surface px-4 md:px-6">
                    <button
                        v-if="!isDesktop"
                        type="button"
                        class="-ml-1 inline-flex size-8 items-center justify-center rounded-md text-secondary hover:bg-surface-hover"
                        aria-label="Open navigation"
                        @click="mobileOpen = true"
                    >
                        <MenuIcon class="size-4" />
                    </button>

                    <div class="flex min-w-0 flex-1 flex-col justify-center">
                        <nav v-if="breadcrumbs.length" aria-label="Breadcrumb" class="flex items-center gap-1 text-xs text-secondary">
                            <template v-for="(crumb, i) in breadcrumbs" :key="i">
                                <a v-if="crumb.href" :href="crumb.href" class="hover:text-ink" @click.prevent="router.visit(crumb.href)">{{ crumb.label }}</a>
                                <span v-else>{{ crumb.label }}</span>
                                <ChevronRight class="size-3 text-muted" aria-hidden="true" />
                            </template>
                        </nav>
                        <h1 class="truncate text-[15px] leading-6 font-semibold text-ink">{{ title }}</h1>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <slot name="actions" />
                        <NotificationBell />
                    </div>
                </header>

                <div v-if="$slots.filters" class="sticky top-0 z-10 shrink-0 border-b border-border bg-surface/95 px-4 backdrop-blur-[2px] md:px-6">
                    <slot name="filters" />
                </div>

                <main id="main" class="flex-1 overflow-y-auto" tabindex="-1">
                    <div :class="['mx-auto w-full px-4 py-6 md:px-6', contentWidth]">
                        <slot />
                    </div>
                </main>
            </div>
        </div>
        <Toaster />
    </TooltipProvider>
</template>
