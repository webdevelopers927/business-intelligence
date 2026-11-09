<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, Ellipsis, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AlertStateBadge from '@/components/alerts/AlertStateBadge.vue';
import EmptyState from '@/components/data/EmptyState.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNumber, formatRelative } from '@/lib/format';
import { formatMetric } from '@/lib/metrics';
import { route } from '@/lib/route';
import { type AlertSummary, FREQUENCY_LABELS, KIND_LABELS } from '@/types/alerts';

defineProps<{ alerts: AlertSummary[] }>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const deleting = ref<AlertSummary | null>(null);

function lastValue(a: AlertSummary): string {
    if (a.last_value === null) return '—';
    if (a.value_is_percent) return `${a.last_value > 0 ? '+' : ''}${formatNumber(a.last_value, 1)}%`;
    if (a.kind !== 'metric') return formatNumber(a.last_value);
    return formatMetric(a.last_value, a.metric_format ?? 'number', currency.value);
}
</script>

<template>
    <AppLayout title="Alerts">
        <template #actions>
            <Button variant="primary" :href="route('alerts.create')"><Plus />New alert</Button>
        </template>

        <div class="overflow-x-auto rounded-lg border border-border bg-surface">
            <table class="w-full min-w-[820px] text-sm">
                <caption class="sr-only">Alerts</caption>
                <thead class="bg-surface-sunken text-left text-xs text-secondary">
                    <tr class="h-8">
                        <th class="pl-4 font-medium">Alert</th>
                        <th class="w-36 px-3 font-medium">Status</th>
                        <th class="w-32 px-3 text-right font-medium">Last value</th>
                        <th class="w-24 px-3 font-medium">Checked</th>
                        <th class="w-36 px-3 font-medium">Last checked</th>
                        <th class="w-36 px-3 font-medium">Last triggered</th>
                        <th class="w-10 pr-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="a in alerts" :key="a.id" class="h-14 hover:bg-surface-hover">
                        <td class="pl-4">
                            <Link :href="route('alerts.show', { alert: a.id })" class="font-medium text-ink hover:text-accent-ink">{{ a.name }}</Link>
                            <p class="text-xs text-secondary">{{ KIND_LABELS[a.kind] }} · {{ a.condition }}</p>
                        </td>
                        <td class="px-3"><AlertStateBadge :state="a.state" :active="a.is_active" :evaluated="a.last_evaluated_at !== null" /></td>
                        <td class="num px-3 text-right text-ink">{{ lastValue(a) }}</td>
                        <td class="px-3 text-xs text-secondary">{{ FREQUENCY_LABELS[a.frequency] }}</td>
                        <td class="px-3 text-xs text-secondary">{{ a.last_evaluated_at ? formatRelative(a.last_evaluated_at) : 'Not yet' }}</td>
                        <td class="px-3 text-xs text-secondary">{{ a.last_triggered_at ? formatRelative(a.last_triggered_at) : 'Never' }}</td>
                        <td class="pr-2 text-right">
                            <Menu width="w-44">
                                <template #trigger><Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${a.name}`"><Ellipsis /></Button></template>
                                <MenuItem @select="router.visit(route('alerts.edit', { alert: a.id }))">Edit</MenuItem>
                                <MenuItem @select="router.post(route('alerts.evaluate', { alert: a.id }), {}, { preserveScroll: true })">Check now</MenuItem>
                                <MenuItem @select="router.post(route('alerts.toggle', { alert: a.id }), {}, { preserveScroll: true })">{{ a.is_active ? 'Pause' : 'Enable' }}</MenuItem>
                                <MenuLabel separator />
                                <MenuItem destructive @select="deleting = a"><Trash2 />Delete</MenuItem>
                            </Menu>
                        </td>
                    </tr>
                    <tr v-if="!alerts.length">
                        <td colspan="7" class="px-4 py-8">
                            <EmptyState
                                :icon="BellRing"
                                title="No alerts yet"
                                description="Get notified when revenue drops, expenses spike, an import fails or a data source stops synchronizing."
                            >
                                <Button variant="primary" size="sm" :href="route('alerts.create')"><Plus />New alert</Button>
                            </EmptyState>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-secondary">Alerts notify recipients once when a condition becomes true, and again only after it has cleared.</p>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name ?? 'alert'}?`"
            description="Its history is removed with it. Notifications already sent are not affected."
            confirm-label="Delete alert"
            @update:open="(o: boolean) => !o && (deleting = null)"
            @confirm="deleting && router.delete(route('alerts.destroy', { alert: deleting.id }), { preserveScroll: true, onFinish: () => (deleting = null) })"
        />
    </AppLayout>
</template>
