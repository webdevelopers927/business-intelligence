<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { Copy, Ellipsis, LayoutDashboard, LayoutGrid, Plus, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import EmptyState from '@/components/data/EmptyState.vue';
import Badge from '@/components/ui/Badge.vue';
import Button from '@/components/ui/Button.vue';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import Dialog from '@/components/ui/Dialog.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRelative } from '@/lib/format';
import { route } from '@/lib/route';

interface Row {
    id: number;
    name: string;
    description: string | null;
    visibility: 'private' | 'organization' | 'shared';
    owner: string | null;
    is_mine: boolean;
    widgets: number;
    updated_at: string | null;
    can: { duplicate: boolean; delete: boolean };
}

defineProps<{ dashboards: Row[]; canCreate: boolean }>();

const createOpen = ref(false);
const form = useForm({ name: '', description: '', visibility: 'private' });
function create() {
    form.post(route('dashboards.store'), { onSuccess: () => (createOpen.value = false) });
}

const deleting = ref<Row | null>(null);
const visibility = { private: 'Private', organization: 'Organization', shared: 'Shared' } as const;
const tone = { private: 'neutral', organization: 'accent', shared: 'accent' } as const;
</script>

<template>
    <AppLayout title="Dashboards">
        <template #actions>
            <Button v-if="canCreate" variant="primary" @click="createOpen = true"><Plus />New dashboard</Button>
        </template>

        <div class="overflow-x-auto rounded-lg border border-border bg-surface">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-surface-sunken text-left text-xs text-secondary">
                    <tr class="h-8">
                        <th class="pl-4 font-medium">Dashboard</th>
                        <th class="w-36 px-3 font-medium">Owner</th>
                        <th class="w-32 px-3 font-medium">Visibility</th>
                        <th class="w-20 px-3 text-right font-medium">Widgets</th>
                        <th class="w-36 px-3 font-medium">Updated</th>
                        <th class="w-10 pr-2"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <!-- Built-in executive dashboard, always available -->
                    <tr class="h-10 hover:bg-surface-hover">
                        <td class="pl-4">
                            <Link :href="route('dashboard')" class="flex items-center gap-2 font-medium text-ink hover:text-accent-ink">
                                <LayoutGrid class="size-4 text-muted" aria-hidden="true" />Executive overview
                            </Link>
                        </td>
                        <td class="px-3 text-secondary">ORBIT</td>
                        <td class="px-3"><Badge>Built-in</Badge></td>
                        <td class="num px-3 text-right text-secondary">—</td>
                        <td class="px-3 text-xs text-secondary">Always current</td>
                        <td />
                    </tr>
                    <tr v-for="d in dashboards" :key="d.id" class="h-10 hover:bg-surface-hover">
                        <td class="pl-4">
                            <Link :href="route('dashboards.show', { dashboard: d.id })" class="flex items-center gap-2 font-medium text-ink hover:text-accent-ink">
                                <LayoutDashboard class="size-4 text-muted" aria-hidden="true" />{{ d.name }}
                            </Link>
                            <p v-if="d.description" class="truncate pl-6 text-xs text-secondary">{{ d.description }}</p>
                        </td>
                        <td class="px-3 text-secondary">{{ d.is_mine ? 'You' : d.owner ?? '—' }}</td>
                        <td class="px-3"><Badge :tone="tone[d.visibility]">{{ visibility[d.visibility] }}</Badge></td>
                        <td class="num px-3 text-right">{{ d.widgets }}</td>
                        <td class="px-3 text-xs text-secondary">{{ formatRelative(d.updated_at) }}</td>
                        <td class="pr-2 text-right">
                            <Menu v-if="d.can.duplicate || d.can.delete" width="w-44">
                                <template #trigger><Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${d.name}`"><Ellipsis /></Button></template>
                                <MenuItem v-if="d.can.duplicate" @select="router.post(route('dashboards.duplicate', { dashboard: d.id }))"><Copy />Duplicate</MenuItem>
                                <template v-if="d.can.delete">
                                    <MenuLabel separator />
                                    <MenuItem destructive @select="deleting = d"><Trash2 />Delete</MenuItem>
                                </template>
                            </Menu>
                        </td>
                    </tr>
                    <tr v-if="!dashboards.length">
                        <td colspan="6" class="px-4 py-8">
                            <EmptyState :icon="LayoutDashboard" title="No custom dashboards yet" description="Build a dashboard from KPIs, trends, breakdowns and tables, then share it with your team.">
                                <Button v-if="canCreate" variant="primary" size="sm" @click="createOpen = true"><Plus />New dashboard</Button>
                            </EmptyState>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <Dialog v-model:open="createOpen" title="New dashboard" description="You'll add widgets on the next screen.">
            <form id="create-dashboard" class="flex flex-col gap-4" novalidate @submit.prevent="create">
                <FormField id="dash-name" label="Name" :error="form.errors.name" required>
                    <template #default="{ id, invalid }"><Input :id="id" v-model="form.name" maxlength="80" autofocus :invalid="invalid" /></template>
                </FormField>
                <FormField id="dash-desc" label="Description" :error="form.errors.description">
                    <template #default="{ id, invalid }"><Input :id="id" v-model="form.description" maxlength="255" :invalid="invalid" /></template>
                </FormField>
                <FormField id="dash-vis" label="Visibility" :error="form.errors.visibility" hint="You can share with specific members later.">
                    <template #default="{ id, describedBy }">
                        <NativeSelect :id="id" v-model="form.visibility" :options="[{ value: 'private', label: 'Only me' }, { value: 'organization', label: 'Everyone in the organization' }]" :aria-describedby="describedBy" />
                    </template>
                </FormField>
            </form>
            <template #footer>
                <Button @click="createOpen = false">Cancel</Button>
                <Button type="submit" form="create-dashboard" variant="primary" :loading="form.processing">Create dashboard</Button>
            </template>
        </Dialog>

        <ConfirmDialog
            :open="deleting !== null"
            :title="`Delete ${deleting?.name ?? 'dashboard'}?`"
            description="The dashboard and its widgets are deleted for everyone it is shared with. Data and metrics are not affected."
            confirm-label="Delete dashboard"
            @update:open="(o: boolean) => !o && (deleting = null)"
            @confirm="deleting && router.delete(route('dashboards.destroy', { dashboard: deleting.id }), { onFinish: () => (deleting = null) })"
        />
    </AppLayout>
</template>
