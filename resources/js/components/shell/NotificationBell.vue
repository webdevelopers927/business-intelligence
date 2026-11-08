<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell } from 'lucide-vue-next';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import Skeleton from '@/components/ui/Skeleton.vue';
import { formatRelative } from '@/lib/format';
import { http, HttpError } from '@/lib/http';
import { route } from '@/lib/route';
import type { AppNotification } from '@/types/domain';

const page = usePage();
const unread = computed(() => page.props.tenant?.unread_notifications ?? 0);

const items = ref<AppNotification[]>([]);
const state = ref<'idle' | 'loading' | 'ready' | 'error'>('idle');
const error = ref('');

async function load() {
    state.value = 'loading';
    try {
        const res = await http.get<{ data: AppNotification[] }>(route('notifications.index'));
        items.value = res.data;
        state.value = 'ready';
    } catch (e) {
        error.value = e instanceof HttpError ? e.message : 'Could not load notifications.';
        state.value = 'error';
    }
}

async function markAllRead() {
    await http.post(route('notifications.read'));
    items.value = items.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }));
    router.reload({ only: ['tenant'] });
}

function open(n: AppNotification) {
    if (n.url) {
        window.location.assign(n.url);
    }
}
</script>

<template>
    <PopoverRoot @update:open="(o: boolean) => o && load()">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="relative inline-flex size-8 items-center justify-center rounded-md text-secondary hover:bg-surface-hover hover:text-ink"
                :aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
            >
                <Bell class="size-4" :stroke-width="1.75" />
                <span
                    v-if="unread > 0"
                    class="num absolute top-1 right-1 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-accent px-1 text-[10px] leading-none font-semibold text-white ring-2 ring-surface"
                    aria-hidden="true"
                >
                    {{ unread > 9 ? '9+' : unread }}
                </span>
            </button>
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                align="end"
                :side-offset="6"
                class="z-50 flex max-h-[420px] w-[min(380px,calc(100vw-24px))] flex-col rounded-lg border border-border bg-surface-raised shadow-overlay focus:outline-none"
            >
                <header class="flex h-11 shrink-0 items-center justify-between border-b border-border px-3.5">
                    <h2 class="text-sm font-semibold text-ink">Notifications</h2>
                    <Button v-if="items.some((n) => !n.read_at)" variant="ghost" size="sm" @click="markAllRead">Mark all read</Button>
                </header>
                <div class="overflow-y-auto">
                    <div v-if="state === 'loading'" class="flex flex-col gap-3 p-3.5">
                        <div v-for="i in 3" :key="i" class="flex flex-col gap-1.5">
                            <Skeleton class="h-3.5 w-2/3" />
                            <Skeleton class="h-3 w-full" />
                        </div>
                    </div>
                    <p v-else-if="state === 'error'" class="p-3.5 text-sm text-negative" role="alert">{{ error }}</p>
                    <div v-else-if="items.length === 0" class="px-3.5 py-6">
                        <p class="text-sm font-medium text-ink">You're all caught up</p>
                        <p class="mt-0.5 text-xs text-secondary">Imports, alerts and report exports will notify you here.</p>
                    </div>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="n in items" :key="n.id">
                            <button
                                type="button"
                                class="flex w-full gap-2.5 px-3.5 py-2.5 text-left hover:bg-surface-hover disabled:cursor-default"
                                :disabled="!n.url"
                                @click="open(n)"
                            >
                                <span :class="['mt-1.5 size-1.5 shrink-0 rounded-full', n.read_at ? 'bg-transparent' : 'bg-accent']" aria-hidden="true" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-ink">{{ n.title }}</span>
                                    <span class="block text-xs text-secondary">{{ n.body }}</span>
                                    <span class="mt-0.5 block text-xs text-muted">{{ formatRelative(n.created_at) }}</span>
                                </span>
                                <span v-if="!n.read_at" class="sr-only">Unread</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
