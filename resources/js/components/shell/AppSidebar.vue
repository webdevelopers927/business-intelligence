<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, LogOut, Monitor, Moon, PanelLeftClose, PanelLeftOpen, Plus, Settings2, Sun, UserRound } from 'lucide-vue-next';
import { DropdownMenuRadioGroup, DropdownMenuRadioItem } from 'reka-ui';
import { computed } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import Menu from '@/components/ui/Menu.vue';
import MenuItem from '@/components/ui/MenuItem.vue';
import MenuLabel from '@/components/ui/MenuLabel.vue';
import Tooltip from '@/components/ui/Tooltip.vue';
import { usePermissions } from '@/composables/usePermissions';
import { useTheme } from '@/composables/useTheme';
import { hasRoute, isCurrentRoute, route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { Theme } from '@/types/domain';
import { type NavItem, primaryNavigation, settingsNavigation } from './navigation';

const props = defineProps<{ collapsed: boolean }>();
const emit = defineEmits<{ toggle: [] }>();

const page = usePage();
const { can } = usePermissions();
const { preference, setTheme } = useTheme();

const tenant = computed(() => page.props.tenant);
const user = computed(() => page.props.auth.user);
const organizations = computed(() => page.props.auth.organizations);

const visible = (item: NavItem) => hasRoute(item.route) && (!item.permission || can(item.permission));
const groups = computed(() =>
    primaryNavigation.map((g) => ({ ...g, items: g.items.filter(visible) })).filter((g) => g.items.length > 0),
);
const settingsItems = computed(() => settingsNavigation.filter(visible));

function switchTo(slug: string) {
    if (slug !== tenant.value?.slug) {
        router.post(route('organizations.switch', { slug }));
    }
}

const themes: { value: Theme; label: string; icon: typeof Sun }[] = [
    { value: 'system', label: 'System', icon: Monitor },
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'dark', label: 'Dark', icon: Moon },
];
</script>

<template>
    <aside
        :class="
            cn(
                'flex h-full flex-col border-r border-border bg-surface transition-[width] duration-[180ms]',
                props.collapsed ? 'w-14' : 'w-[232px]',
            )
        "
        aria-label="Primary"
    >
        <!-- Organization switcher -->
        <div class="flex h-14 shrink-0 items-center gap-1 border-b border-border px-2.5">
            <Menu align="start" width="w-64">
                <template #trigger>
                    <button
                        type="button"
                        :class="
                            cn(
                                'flex min-w-0 flex-1 items-center gap-2 rounded-md p-1 text-left hover:bg-surface-hover',
                                props.collapsed && 'justify-center',
                            )
                        "
                        :aria-label="`Organization: ${tenant?.name}. Switch organization`"
                    >
                        <Avatar :name="tenant?.name ?? 'Org'" square />
                        <template v-if="!props.collapsed">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-ink">{{ tenant?.name }}</span>
                                <span class="block truncate text-xs text-secondary">{{ tenant?.role_label }}</span>
                            </span>
                            <ChevronsUpDown class="size-4 shrink-0 text-muted" aria-hidden="true" />
                        </template>
                    </button>
                </template>
                <MenuLabel>Organizations</MenuLabel>
                <MenuItem v-for="org in organizations" :key="org.id" @select="switchTo(org.slug)">
                    <Avatar :name="org.name" size="sm" square />
                    <span class="flex-1 truncate">{{ org.name }}</span>
                    <Check v-if="org.slug === tenant?.slug" class="text-accent!" aria-label="Current" />
                </MenuItem>
                <MenuLabel separator />
                <MenuItem @select="router.visit(route('onboarding.create'))">
                    <Plus />
                    New organization
                </MenuItem>
            </Menu>
        </div>

        <!-- Primary navigation -->
        <nav class="flex-1 overflow-y-auto px-2 py-3">
            <div v-for="group in groups" :key="group.label" class="mb-4">
                <p v-if="!props.collapsed" class="label-instrument mb-1 px-2">{{ group.label }}</p>
                <ul class="flex flex-col gap-px">
                    <li v-for="item in group.items" :key="item.route">
                        <Tooltip :content="item.label" side="right" :disabled="!props.collapsed">
                            <Link
                                :href="route(item.route)"
                                :aria-current="isCurrentRoute(item.match) ? 'page' : undefined"
                                :class="
                                    cn(
                                        'relative flex h-8 items-center gap-2.5 rounded-md px-2 text-sm font-medium text-secondary transition-colors duration-[120ms] hover:bg-surface-hover hover:text-ink',
                                        'aria-[current=page]:bg-accent-subtle aria-[current=page]:text-accent-ink',
                                        'aria-[current=page]:before:absolute aria-[current=page]:before:inset-y-1.5 aria-[current=page]:before:-left-2 aria-[current=page]:before:w-0.5 aria-[current=page]:before:rounded-r-sm aria-[current=page]:before:bg-accent',
                                        props.collapsed && 'justify-center px-0',
                                    )
                                "
                            >
                                <component :is="item.icon" class="size-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                                <span v-if="!props.collapsed" class="truncate">{{ item.label }}</span>
                            </Link>
                        </Tooltip>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Settings + user -->
        <div class="shrink-0 border-t border-border px-2 py-2">
            <ul v-if="settingsItems.length" class="mb-2 flex flex-col gap-px">
                <li v-for="item in settingsItems" :key="item.route">
                    <Tooltip :content="item.label" side="right" :disabled="!props.collapsed">
                        <Link
                            :href="route(item.route)"
                            :aria-current="isCurrentRoute(item.match) ? 'page' : undefined"
                            :class="
                                cn(
                                    'flex h-8 items-center gap-2.5 rounded-md px-2 text-sm font-medium text-secondary hover:bg-surface-hover hover:text-ink',
                                    'aria-[current=page]:bg-accent-subtle aria-[current=page]:text-accent-ink',
                                    props.collapsed && 'justify-center px-0',
                                )
                            "
                        >
                            <component :is="item.icon" class="size-4 shrink-0" :stroke-width="1.75" aria-hidden="true" />
                            <span v-if="!props.collapsed">{{ item.label }}</span>
                        </Link>
                    </Tooltip>
                </li>
            </ul>

            <div :class="cn('flex items-center gap-1', props.collapsed && 'flex-col')">
                <Menu align="start" side="top" width="w-60">
                    <template #trigger>
                        <button
                            type="button"
                            :class="cn('flex min-w-0 flex-1 items-center gap-2 rounded-md p-1 text-left hover:bg-surface-hover', props.collapsed && 'justify-center')"
                            aria-label="Account menu"
                        >
                            <Avatar :name="user?.name ?? '?'" />
                            <span v-if="!props.collapsed" class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-ink">{{ user?.name }}</span>
                                <span class="block truncate text-xs text-secondary">{{ user?.email }}</span>
                            </span>
                        </button>
                    </template>
                    <MenuItem @select="router.visit(route('profile.edit'))">
                        <UserRound />
                        Profile
                    </MenuItem>
                    <MenuItem v-if="can('org.settings.manage')" @select="router.visit(route('settings.organization.edit'))">
                        <Settings2 />
                        Organization settings
                    </MenuItem>
                    <MenuLabel separator />
                    <MenuLabel>Theme</MenuLabel>
                    <DropdownMenuRadioGroup :model-value="preference" @update:model-value="(v) => setTheme(v as Theme)">
                        <DropdownMenuRadioItem
                            v-for="t in themes"
                            :key="t.value"
                            :value="t.value"
                            class="flex h-8 cursor-default items-center gap-2 rounded-sm px-2 text-sm text-ink outline-none select-none data-[highlighted]:bg-surface-hover [&_svg]:size-4"
                        >
                            <component :is="t.icon" class="text-secondary" aria-hidden="true" />
                            <span class="flex-1">{{ t.label }}</span>
                            <Check v-if="preference === t.value" class="text-accent" aria-hidden="true" />
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                    <MenuLabel separator />
                    <MenuItem @select="router.post(route('logout'))">
                        <LogOut />
                        Sign out
                    </MenuItem>
                </Menu>

                <Tooltip :content="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'" side="right">
                    <button
                        type="button"
                        class="hidden size-8 shrink-0 items-center justify-center rounded-md text-muted hover:bg-surface-hover hover:text-ink md:inline-flex"
                        :aria-label="props.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        :aria-expanded="!props.collapsed"
                        @click="emit('toggle')"
                    >
                        <PanelLeftOpen v-if="props.collapsed" class="size-4" />
                        <PanelLeftClose v-else class="size-4" />
                    </button>
                </Tooltip>
            </div>
        </div>
    </aside>
</template>
