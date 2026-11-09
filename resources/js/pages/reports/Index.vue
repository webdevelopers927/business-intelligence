<script setup lang="ts">
import { Link, router, usePoll } from '@inertiajs/vue3';
import { CalendarClock, Download, Ellipsis, FileText, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/data/EmptyState.vue';
import StatusBadge from '@/components/data/StatusBadge.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNumber, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { ReportRunRow, ReportTypeInfo } from '@/types/reports';

interface SavedRow {
    id: number;
    name: string;
    type: string;
    type_label: string;
    created_by: string | null;
    schedule: string | null;
    updated_at: string | null;
    can_manage: boolean;
}

const props = defineProps<{ types: ReportTypeInfo[]; saved: SavedRow[]; runs: ReportRunRow[]; canCreate: boolean }>();

const deleting = ref<SavedRow | null>(null);

// Keep queued exports live while they're being prepared.
const pending = computed(() => props.runs.some((r) => r.status === 'queued' || r.status === 'running'));
const { start, stop } = usePoll(3000, { only: ['runs'] }, { autoStart: false });
watch(pending, (p) => (p ? start() : stop()), { immediate: true });

const statusLabel: Record<ReportRunRow['status'], string> = { queued: 'Queued', running: 'Generating', completed: 'Ready', failed: 'Failed' };
const triggerLabel: Record<ReportRunRow['trigger'], string> = { manual: 'Manual', queued: 'Background', schedule: 'Scheduled' };
</script>

<template>
    <AppLayout title="Reports">
        <template #actions>
            <Button variant="primary" :href="route('reports.create')"><Plus />New report</Button>
        </template>

        <section aria-labelledby="types-heading">
            <h2 id="types-heading" class="mb-2 text-sm font-semibold text-ink">Start from a report</h2>
            <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <li v-for="t in types" :key="t.key">
                    <Link
                        :href="route('reports.create', { type: t.key })"
                        class="flex h-full flex-col gap-1 rounded-lg border border-border bg-surface p-4 transition-colors duration-[120ms] hover:border-accent hover:bg-surface-hover"
                    >
                        <span class="flex items-center gap-2 text-sm font-medium text-ink"><FileText class="size-4 text-accent" aria-hidden="true" />{{ t.label }}</span>
                        <span class="text-xs text-secondary">{{ t.description }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section class="mt-8" aria-labelledby="saved-heading">
            <h2 id="saved-heading" class="mb-2 text-sm font-semibold text-ink">Saved reports <span class="num ml-1 font-normal text-secondary">{{ saved.length }}</span></h2>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[680px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8">
                            <th class="pl-4 font-medium">Report</th>
                            <th class="w-48 px-3 font-medium">Type</th>
                            <th class="w-64 px-3 font-medium">Schedule</th>
                            <th class="w-60 px-3 font-medium">Updated</th>
                            <th class="w-10 pr-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="r in saved" :key="r.id" class="h-11 hover:bg-surface-hover">
                            <td class="pl-4">
                                <Link :href="route('reports.show', { report: r.id })" class="font-medium text-ink hover:text-accent-ink">{{ r.name }}</Link>
                            </td>
                            <td class="px-3 text-secondary">{{ r.type_label }}</td>
                            <td class="px-3 text-xs">
                                <span v-if="r.schedule" class="inline-flex items-center gap-1.5 text-ink"><CalendarClock class="size-3.5 text-accent" aria-hidden="true" />{{ r.schedule }}</span>
                                <span v-else class="text-muted">Not scheduled</span>
                            </td>
                            <td class="px-3 text-xs whitespace-nowrap text-secondary">{{ formatRelative(r.updated_at) }}<span v-if="r.created_by"> · {{ r.created_by }}</span></td>
                            <td class="pr-2 text-right">
                                <Menu v-if="r.can_manage" width="w-40">
                                    <template #trigger><Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${r.name}`"><Ellipsis /></Button></template>
                                    <MenuItem @select="router.visit(route('reports.show', { report: r.id }))">Open</MenuItem>
                                    <MenuLabel separator />
                                    <MenuItem destructive @select="deleting = r"><Trash2 />Delete</MenuItem>
                                </Menu>
                            </td>
                        </tr>
                        <tr v-if="!saved.length">
                            <td colspan="5" class="px-4 py-8">
                                <EmptyState
                                    :icon="FileText"
                                    title="No saved reports yet"
                                    :description="canCreate ? 'Configure a report, then save it to reuse the settings or deliver it on a schedule.' : 'Saved reports created by your team appear here.'"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-8" aria-labelledby="runs-heading">
            <h2 id="runs-heading" class="mb-2 text-sm font-semibold text-ink">Recent exports</h2>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[720px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8">
                            <th class="pl-4 font-medium">Export</th>
                            <th class="w-20 px-3 font-medium">Format</th>
                            <th class="w-24 px-3 text-right font-medium">Rows</th>
                            <th class="w-28 px-3 font-medium">Trigger</th>
                            <th class="w-44 px-3 font-medium">Requested</th>
                            <th class="w-32 px-3 font-medium">Status</th>
                            <th class="w-12 pr-3"><span class="sr-only">Download</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="run in runs" :key="run.id" class="h-10">
                            <td class="pl-4 text-ink">{{ run.name }}</td>
                            <td class="px-3 text-xs text-secondary uppercase">{{ run.format }}</td>
                            <td class="num px-3 text-right text-secondary">{{ run.row_count === null ? '—' : formatNumber(run.row_count) }}</td>
                            <td class="px-3 text-xs text-secondary">{{ triggerLabel[run.trigger] }}</td>
                            <td class="px-3 text-xs text-secondary">{{ formatRelative(run.created_at) }}<span v-if="run.requested_by"> · {{ run.requested_by }}</span></td>
                            <td class="px-3"><StatusBadge :status="run.status === 'running' || run.status === 'queued' ? 'processing' : run.status" :label="statusLabel[run.status]" /></td>
                            <td class="pr-3 text-right">
                                <Button v-if="run.download_url" variant="ghost" size="icon-sm" :href="run.download_url" external :aria-label="`Download ${run.name}`"><Download /></Button>
                            </td>
                        </tr>
                        <tr v-if="!runs.length">
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-secondary">Exports you generate appear here.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name ?? 'report'}?`"
            description="Its schedule stops. Files already exported stay available in recent exports."
            confirm-label="Delete report"
            @update:open="(o: boolean) => !o && (deleting = null)"
            @confirm="deleting && router.delete(route('reports.destroy', { report: deleting.id }), { preserveScroll: true, onFinish: () => (deleting = null) })"
        />
    </AppLayout>
</template>
