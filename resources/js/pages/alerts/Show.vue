<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Pause, Pencil, Play, RefreshCw } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import AlertStateBadge from '@/components/alerts/AlertStateBadge.vue';
import TablePagination from '@/components/data/TablePagination.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate, formatNumber, formatRelative } from '@/lib/format';
import { formatMetric } from '@/lib/metrics';
import { route } from '@/lib/route';
import { type AlertSummary, FREQUENCY_LABELS, KIND_LABELS } from '@/types/alerts';
import type { Paginated } from '@/types/table';

interface Execution {
    id: number;
    evaluated_at: string;
    outcome: 'ok' | 'triggered' | 'resolved' | 'error';
    notified: boolean;
    message: string | null;
}

const props = defineProps<{
    alert: AlertSummary & { next_evaluation_at: string | null; recipients: string[] };
    executions: Paginated<Execution>;
}>();

const currency = computed(() => usePage().props.tenant?.currency ?? 'PKR');
const checking = ref(false);

const lastValue = computed(() => {
    const a = props.alert;
    if (a.last_value === null) return '—';
    if (a.value_is_percent) return `${a.last_value > 0 ? '+' : ''}${formatNumber(a.last_value, 1)}%`;
    if (a.kind !== 'metric') return formatNumber(a.last_value);
    return formatMetric(a.last_value, a.metric_format ?? 'number', currency.value);
});

const outcome: Record<Execution['outcome'], { label: string; tone: 'neutral' | 'negative' | 'positive' | 'warning' }> = {
    ok: { label: 'OK', tone: 'neutral' },
    triggered: { label: 'Triggered', tone: 'negative' },
    resolved: { label: 'Resolved', tone: 'positive' },
    error: { label: 'Error', tone: 'warning' },
};

function checkNow() {
    checking.value = true;
    router.post(route('alerts.evaluate', { alert: props.alert.id }), {}, { preserveScroll: true, onFinish: () => (checking.value = false) });
}

function page(p: number) {
    router.get(route('alerts.show', { alert: props.alert.id }), { page: p }, { preserveScroll: true, preserveState: true, only: ['executions'] });
}

const dt = (iso: string | null) => (iso ? formatDate(iso, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
</script>

<template>
    <AppLayout :title="alert.name" :breadcrumbs="[{ label: 'Alerts', href: route('alerts.index') }]">
        <template #actions>
            <Button :loading="checking" @click="checkNow"><RefreshCw />Check now</Button>
            <Button variant="ghost" @click="router.post(route('alerts.toggle', { alert: alert.id }), {}, { preserveScroll: true })">
                <template v-if="alert.is_active"><Pause />Pause</template>
                <template v-else><Play />Enable</template>
            </Button>
            <Button variant="ghost" :href="route('alerts.edit', { alert: alert.id })"><Pencil />Edit</Button>
        </template>

        <dl class="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 xl:grid-cols-4">
            <div class="bg-surface p-4">
                <dt class="label-instrument">Status</dt>
                <dd class="mt-1.5"><AlertStateBadge :state="alert.state" :active="alert.is_active" :evaluated="alert.last_evaluated_at !== null" /></dd>
            </div>
            <div class="bg-surface p-4">
                <dt class="label-instrument">Last value</dt>
                <dd class="num mt-1 text-lg font-semibold text-ink">{{ lastValue }}</dd>
            </div>
            <div class="bg-surface p-4">
                <dt class="label-instrument">Last checked</dt>
                <dd class="mt-1 text-sm text-ink">{{ alert.last_evaluated_at ? formatRelative(alert.last_evaluated_at) : 'Not yet' }}</dd>
                <dd v-if="alert.is_active && alert.next_evaluation_at" class="text-xs text-secondary">Next {{ dt(alert.next_evaluation_at) }}</dd>
            </div>
            <div class="bg-surface p-4">
                <dt class="label-instrument">Last triggered</dt>
                <dd class="mt-1 text-sm text-ink">{{ alert.last_triggered_at ? formatRelative(alert.last_triggered_at) : 'Never' }}</dd>
            </div>
        </dl>

        <section class="mt-6 rounded-lg border border-border bg-surface p-4 text-sm" aria-label="Alert definition">
            <p class="text-ink"><span class="text-secondary">{{ KIND_LABELS[alert.kind] }}:</span> {{ alert.condition }}</p>
            <p class="mt-1 text-secondary">Checked {{ FREQUENCY_LABELS[alert.frequency].toLowerCase() }} · notifies {{ alert.recipients.join(', ') || 'nobody' }}</p>
        </section>

        <section class="mt-6" aria-labelledby="history-heading">
            <h2 id="history-heading" class="mb-2 text-sm font-semibold text-ink">History <span class="num ml-1 font-normal text-secondary">{{ executions.meta.total }}</span></h2>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8">
                            <th class="w-40 pl-4 font-medium">Checked</th>
                            <th class="w-28 px-3 font-medium">Outcome</th>
                            <th class="px-3 font-medium">Details</th>
                            <th class="w-24 pr-4 font-medium">Notified</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="e in executions.data" :key="e.id" class="h-10">
                            <td class="pl-4 text-xs text-secondary">{{ dt(e.evaluated_at) }}</td>
                            <td class="px-3"><Badge :tone="outcome[e.outcome].tone" dot>{{ outcome[e.outcome].label }}</Badge></td>
                            <td class="px-3 py-2 text-ink">{{ e.message }}</td>
                            <td class="pr-4 text-xs text-secondary">{{ e.notified ? 'Yes' : '—' }}</td>
                        </tr>
                        <tr v-if="!executions.data.length">
                            <td colspan="4" class="px-4 py-8 text-center text-sm text-secondary">This alert hasn't been checked yet. Use “Check now” or wait for the next scheduled check.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <TablePagination :meta="executions.meta" :per-page-options="[]" @page="page" />
        </section>
    </AppLayout>
</template>
