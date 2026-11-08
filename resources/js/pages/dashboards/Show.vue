<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useDebounceFn, useMediaQuery } from '@vueuse/core';
import { GridItem, GridLayout } from 'grid-layout-plus';
import { Check, Copy, Ellipsis, GripVertical, LayoutDashboard, LoaderCircle, Pencil, Plus, Share2, Trash2, TriangleAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import FilterBar from '@/components/analytics/FilterBar.vue';
import ShareDialog from '@/components/dashboards/ShareDialog.vue';
import WidgetEditor from '@/components/dashboards/WidgetEditor.vue';
import WidgetView from '@/components/dashboards/WidgetView.vue';
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
import { filterParams } from '@/composables/useAnalyticsFilters';
import AppLayout from '@/layouts/AppLayout.vue';
import { http } from '@/lib/http';
import { route } from '@/lib/route';
import type { AnalyticsFilterState, AnalyticsOptions, Period } from '@/types/analytics';
import type { Catalog, Widget } from '@/types/dashboards';

const props = defineProps<{
    dashboard: {
        id: number;
        name: string;
        description: string | null;
        visibility: 'private' | 'organization' | 'shared';
        owner: string | null;
        updated_at: string | null;
        can: { update: boolean; delete: boolean; share: boolean; duplicate: boolean };
    };
    widgets: Widget[];
    filters: AnalyticsFilterState;
    options: AnalyticsOptions;
    period: Period;
    catalog: Catalog;
    sharing: { members: { id: number; name: string; email: string; role: string }[]; shares: { user_id: number; permission: 'view' | 'edit' }[] } | null;
    startEditing: boolean;
}>();

const ROW_HEIGHT = 56;
const isDesktop = useMediaQuery('(min-width: 1024px)');
const editing = ref(props.startEditing && props.dashboard.can.update);
const canEditHere = computed(() => editing.value && isDesktop.value);

// ── Grid state ─────────────────────────────────────────────────────────
interface GridEntry {
    i: number;
    x: number;
    y: number;
    w: number;
    h: number;
    minW: number;
    minH: number;
}
const toGrid = (ws: Widget[]): GridEntry[] => ws.map((w) => ({ i: w.id, x: w.x, y: w.y, w: w.w, h: w.h, minW: w.min_w, minH: w.min_h }));
const layout = ref<GridEntry[]>(toGrid(props.widgets));
const byId = computed(() => new Map(props.widgets.map((w) => [w.id, w])));
watch(() => props.widgets, (ws) => (layout.value = toGrid(ws)));

const stacked = computed(() => [...props.widgets].sort((a, b) => a.y - b.y || a.x - b.x));

// ── Layout persistence: debounced, with a visible save state ───────────
const saveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
let lastSaved = JSON.stringify(layout.value.map(({ i, x, y, w, h }) => ({ id: i, x, y, w, h })));

async function persist() {
    const items = layout.value.map(({ i, x, y, w, h }) => ({ id: i, x, y, w, h }));
    const serialized = JSON.stringify(items);
    if (serialized === lastSaved) return;
    saveState.value = 'saving';
    try {
        await http.put(route('dashboards.layout', { dashboard: props.dashboard.id }), { items });
        lastSaved = serialized;
        saveState.value = 'saved';
    } catch {
        saveState.value = 'error';
    }
}
const persistSoon = useDebounceFn(persist, 800);
function onLayoutUpdated() {
    if (canEditHere.value) persistSoon();
}

// ── Widget data URL carries the dashboard filters ──────────────────────
const query = computed(() => `?${new URLSearchParams(filterParams(props.filters)).toString()}`);
const dataUrl = (id: number) => route('dashboards.widgets.data', { dashboard: props.dashboard.id, widget: id });

// ── Dialogs ────────────────────────────────────────────────────────────
const editorOpen = ref(false);
const editing_widget = ref<Widget | null>(null);
function addWidget() {
    editing_widget.value = null;
    editorOpen.value = true;
}
function editWidget(w: Widget) {
    editing_widget.value = w;
    editorOpen.value = true;
}

const removing = ref<Widget | null>(null);
function confirmRemove() {
    if (!removing.value) return;
    router.delete(route('dashboards.widgets.destroy', { dashboard: props.dashboard.id, widget: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}

const shareOpen = ref(false);
const renameOpen = ref(false);
const rename = ref({ name: props.dashboard.name, description: props.dashboard.description ?? '' });
const renameErrors = ref<Record<string, string>>({});
function saveRename() {
    router.patch(route('dashboards.update', { dashboard: props.dashboard.id }), rename.value, {
        preserveScroll: true,
        onSuccess: () => (renameOpen.value = false),
        onError: (e) => (renameErrors.value = e),
    });
}
const deleteOpen = ref(false);

const visibilityLabel = { private: 'Private', organization: 'Organization', shared: 'Shared' } as const;
const widgetMeta = (w: Widget) => {
    const metric = props.catalog.metrics.find((m) => m.key === w.config.metric)?.label ?? w.config.metric;
    return w.config.range.mode === 'fixed' ? `${metric} · ${props.options.presets.find((p) => p.value === w.config.range.preset)?.label ?? ''}` : metric;
};
</script>

<template>
    <AppLayout :title="dashboard.name" :breadcrumbs="[{ label: 'Dashboards', href: route('dashboards.index') }]">
        <template #actions>
            <template v-if="!editing">
                <Badge class="hidden sm:inline-flex">{{ visibilityLabel[dashboard.visibility] }}</Badge>
                <Button v-if="dashboard.can.share" @click="shareOpen = true"><Share2 />Share</Button>
                <Button v-if="dashboard.can.update && isDesktop" variant="primary" @click="editing = true"><Pencil />Edit</Button>
                <Menu v-if="dashboard.can.update || dashboard.can.duplicate || dashboard.can.delete" width="w-48">
                    <template #trigger><Button variant="ghost" size="icon" aria-label="Dashboard options"><Ellipsis /></Button></template>
                    <MenuItem v-if="dashboard.can.update" @select="renameOpen = true"><Pencil />Rename</MenuItem>
                    <MenuItem v-if="dashboard.can.duplicate" @select="router.post(route('dashboards.duplicate', { dashboard: dashboard.id }))"><Copy />Duplicate</MenuItem>
                    <template v-if="dashboard.can.delete">
                        <MenuLabel separator />
                        <MenuItem destructive @select="deleteOpen = true"><Trash2 />Delete dashboard</MenuItem>
                    </template>
                </Menu>
            </template>
            <template v-else>
                <span class="hidden items-center gap-1.5 text-xs text-secondary sm:flex" aria-live="polite">
                    <template v-if="saveState === 'saving'"><LoaderCircle class="size-3.5 animate-spin" />Saving layout…</template>
                    <template v-else-if="saveState === 'saved'"><Check class="size-3.5 text-positive" />Layout saved</template>
                    <template v-else-if="saveState === 'error'">
                        <TriangleAlert class="size-3.5 text-negative" />Couldn't save
                        <button type="button" class="font-medium text-accent-ink hover:underline" @click="persist">Retry</button>
                    </template>
                    <template v-else>Drag by the handle · resize from the corner</template>
                </span>
                <Button @click="addWidget"><Plus />Add widget</Button>
                <Button variant="primary" @click="persist().then(() => (editing = false))">Done</Button>
            </template>
        </template>

        <template #filters>
            <FilterBar :filters="filters" :options="options" :period="period" />
        </template>

        <p v-if="dashboard.description" class="mb-4 max-w-prose text-sm text-secondary">{{ dashboard.description }}</p>

        <div v-if="!widgets.length" class="rounded-lg border border-dashed border-border-strong bg-surface p-8">
            <EmptyState :icon="LayoutDashboard" title="This dashboard has no widgets yet" description="Add KPIs, trends, breakdowns and tables. Each widget reads live from your data and follows the filters above.">
                <Button v-if="dashboard.can.update" variant="primary" size="sm" @click="editing = true; addWidget()"><Plus />Add the first widget</Button>
            </EmptyState>
        </div>

        <!-- Desktop: the persisted 12-column grid -->
        <GridLayout
            v-else-if="isDesktop"
            v-model:layout="layout"
            :col-num="12"
            :row-height="ROW_HEIGHT"
            :margin="[16, 16]"
            :is-draggable="canEditHere"
            :is-resizable="canEditHere"
            :vertical-compact="true"
            :use-css-transforms="true"
            :class="['-mx-4', editing && 'orbit-grid-editing']"
            @layout-updated="onLayoutUpdated"
        >
            <GridItem
                v-for="item in layout"
                :key="item.i"
                :i="item.i"
                :x="item.x"
                :y="item.y"
                :w="item.w"
                :h="item.h"
                :min-w="item.minW"
                :min-h="item.minH"
                drag-allow-from=".widget-drag"
            >
                <section v-if="byId.get(item.i)" class="flex h-full min-w-0 flex-col rounded-lg border border-border bg-surface" :aria-label="byId.get(item.i)!.title">
                    <!--
                        In edit mode the whole header is the drag handle. Buttons inside
                        stay clickable: grid items ignore drags that start on a/button.
                    -->
                    <header :class="['flex h-10 shrink-0 items-center gap-2 border-b border-border px-3', canEditHere && 'widget-drag cursor-grab select-none active:cursor-grabbing']">
                        <GripVertical v-if="canEditHere" class="-ml-1 size-4 shrink-0 text-muted" aria-hidden="true" />
                        <h2 class="min-w-0 flex-1 truncate text-sm font-semibold text-ink">{{ byId.get(item.i)!.title }}</h2>
                        <span v-if="!canEditHere" class="label-instrument hidden truncate xl:inline">{{ widgetMeta(byId.get(item.i)!) }}</span>
                        <template v-if="canEditHere">
                            <Button variant="ghost" size="icon-sm" :aria-label="`Edit ${byId.get(item.i)!.title}`" @click="editWidget(byId.get(item.i)!)"><Pencil /></Button>
                            <Button variant="ghost" size="icon-sm" :aria-label="`Remove ${byId.get(item.i)!.title}`" @click="removing = byId.get(item.i)!"><Trash2 /></Button>
                        </template>
                    </header>
                    <div class="min-h-0 flex-1 p-3">
                        <WidgetView :widget="byId.get(item.i)!" :data-url="dataUrl(item.i)" :query="query" />
                    </div>
                </section>
            </GridItem>
        </GridLayout>

        <!-- Small screens: read-only stack in layout order -->
        <div v-else class="flex flex-col gap-4">
            <p v-if="dashboard.can.update" class="text-xs text-secondary">Editing the layout is available on larger screens.</p>
            <section v-for="w in stacked" :key="w.id" class="flex flex-col rounded-lg border border-border bg-surface" :style="{ height: `${Math.max(2, w.h) * (ROW_HEIGHT - 8)}px` }" :aria-label="w.title">
                <header class="flex h-10 shrink-0 items-center border-b border-border px-3"><h2 class="truncate text-sm font-semibold text-ink">{{ w.title }}</h2></header>
                <div class="min-h-0 flex-1 p-3"><WidgetView :widget="w" :data-url="dataUrl(w.id)" :query="query" /></div>
            </section>
        </div>

        <WidgetEditor v-model:open="editorOpen" :dashboard-id="dashboard.id" :widget="editing_widget" :catalog="catalog" :options="options" />

        <ShareDialog
            v-if="sharing"
            v-model:open="shareOpen"
            :dashboard-id="dashboard.id"
            :visibility="dashboard.visibility"
            :members="sharing.members"
            :shares="sharing.shares"
        />

        <Dialog v-model:open="renameOpen" title="Rename dashboard">
            <form id="rename-form" class="flex flex-col gap-4" @submit.prevent="saveRename">
                <FormField id="d-name" label="Name" :error="renameErrors.name" required>
                    <template #default="{ id, invalid }"><Input :id="id" v-model="rename.name" maxlength="80" :invalid="invalid" /></template>
                </FormField>
                <FormField id="d-desc" label="Description" :error="renameErrors.description">
                    <template #default="{ id, invalid }"><Input :id="id" v-model="rename.description" maxlength="255" :invalid="invalid" /></template>
                </FormField>
            </form>
            <template #footer>
                <Button @click="renameOpen = false">Cancel</Button>
                <Button type="submit" form="rename-form" variant="primary">Save</Button>
            </template>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove ${removing?.title ?? 'widget'}?`"
            description="The widget is removed from this dashboard. Your data and metrics are not affected."
            confirm-label="Remove widget"
            @update:open="(o: boolean) => !o && (removing = null)"
            @confirm="confirmRemove"
        />

        <ConfirmDialog
            v-model:open="deleteOpen"
            :title="`Delete ${dashboard.name}?`"
            description="The dashboard and its widgets are deleted for everyone it is shared with. Data and metrics are not affected."
            confirm-label="Delete dashboard"
            @confirm="router.delete(route('dashboards.destroy', { dashboard: dashboard.id }))"
        />
    </AppLayout>
</template>

<style>
/* Grid chrome tuned to ORBIT tokens (grid-layout-plus ships neutral defaults). */
.vgl-layout {
    --vgl-placeholder-bg: var(--accent);
    --vgl-placeholder-opacity: 12%;
    --vgl-resizer-border-color: var(--text-muted);
}
.vgl-item--placeholder {
    border-radius: 8px;
    border: 1px dashed var(--accent);
}
.orbit-grid-editing .vgl-item:not(.vgl-item--placeholder) > section {
    box-shadow: var(--elevation-1);
}
.vgl-item--dragging > section {
    box-shadow: var(--elevation-2) !important;
}
</style>
