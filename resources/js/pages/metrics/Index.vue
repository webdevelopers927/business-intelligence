<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Ellipsis, Plus, Sigma, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import EmptyState from '@/components/data/EmptyState.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';

interface CustomRow {
    id: number;
    key: string;
    name: string;
    description: string | null;
    formula: string;
    format: string;
    visibility: 'private' | 'organization';
    created_by: string | null;
    updated_at: string | null;
    can_manage: boolean;
}

defineProps<{
    base: { key: string; label: string; format: string; description: string }[];
    custom: CustomRow[];
    canCreate: boolean;
}>();

const deleting = ref<CustomRow | null>(null);
const formatLabel: Record<string, string> = { currency: 'Currency', number: 'Number', percent: 'Percent' };
</script>

<template>
    <AppLayout title="Metrics">
        <template #actions>
            <Button v-if="canCreate" variant="primary" :href="route('metrics.create')"><Plus />New metric</Button>
        </template>

        <section aria-labelledby="custom-heading">
            <div class="mb-2 flex items-baseline justify-between">
                <h2 id="custom-heading" class="text-sm font-semibold text-ink">Custom metrics <span class="num ml-1 font-normal text-secondary">{{ custom.length }}</span></h2>
                <p class="hidden text-xs text-secondary sm:block">Formulas over other metrics, usable in every dashboard widget.</p>
            </div>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8">
                            <th class="pl-4 font-medium">Metric</th>
                            <th class="px-3 font-medium">Formula</th>
                            <th class="w-24 px-3 font-medium">Format</th>
                            <th class="w-28 px-3 font-medium">Visibility</th>
                            <th class="w-32 px-3 font-medium">Updated</th>
                            <th class="w-10 pr-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="m in custom" :key="m.id" class="h-11 hover:bg-surface-hover">
                            <td class="pl-4">
                                <Link v-if="m.can_manage" :href="route('metrics.edit', { metric: m.id })" class="font-medium text-ink hover:text-accent-ink">{{ m.name }}</Link>
                                <span v-else class="font-medium text-ink">{{ m.name }}</span>
                                <p class="font-mono text-[11px] text-muted">{{ m.key }}</p>
                            </td>
                            <td class="max-w-80 px-3"><code class="block truncate font-mono text-xs text-ink" :title="m.formula">{{ m.formula }}</code></td>
                            <td class="px-3 text-secondary">{{ formatLabel[m.format] }}</td>
                            <td class="px-3"><Badge :tone="m.visibility === 'organization' ? 'accent' : 'neutral'">{{ m.visibility === 'organization' ? 'Organization' : 'Private' }}</Badge></td>
                            <td class="px-3 text-xs text-secondary">{{ formatRelative(m.updated_at) }}<span v-if="m.created_by"> · {{ m.created_by }}</span></td>
                            <td class="pr-2 text-right">
                                <Menu v-if="m.can_manage" width="w-40">
                                    <template #trigger><Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${m.name}`"><Ellipsis /></Button></template>
                                    <MenuItem @select="router.visit(route('metrics.edit', { metric: m.id }))">Edit</MenuItem>
                                    <MenuLabel separator />
                                    <MenuItem destructive @select="deleting = m"><Trash2 />Delete</MenuItem>
                                </Menu>
                            </td>
                        </tr>
                        <tr v-if="!custom.length">
                            <td colspan="6" class="px-4 py-8">
                                <EmptyState :icon="Sigma" title="No custom metrics yet" description="Combine existing metrics with + − × ÷, for example net_profit / orders for profit per order.">
                                    <Button v-if="canCreate" variant="primary" size="sm" :href="route('metrics.create')"><Plus />New metric</Button>
                                </EmptyState>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-8" aria-labelledby="base-heading">
            <h2 id="base-heading" class="mb-2 text-sm font-semibold text-ink">Built-in metrics <span class="num ml-1 font-normal text-secondary">{{ base.length }}</span></h2>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8"><th class="pl-4 font-medium">Metric</th><th class="px-3 font-medium">Definition</th><th class="w-24 pr-4 font-medium">Format</th></tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="m in base" :key="m.key" class="h-10">
                            <td class="pl-4"><span class="text-ink">{{ m.label }}</span> <code class="ml-1 font-mono text-[11px] text-muted">{{ m.key }}</code></td>
                            <td class="px-3 text-secondary">{{ m.description }}</td>
                            <td class="pr-4 text-secondary">{{ formatLabel[m.format] }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name ?? 'metric'}?`"
            description="Metrics used by dashboard widgets or other metrics can't be deleted until those uses are removed."
            confirm-label="Delete metric"
            @update:open="(o: boolean) => !o && (deleting = null)"
            @confirm="deleting && router.delete(route('metrics.destroy', { metric: deleting.id }), { preserveScroll: true, onFinish: () => (deleting = null) })"
        />
    </AppLayout>
</template>
