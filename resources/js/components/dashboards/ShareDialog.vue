<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Building2, Lock, Users } from 'lucide-vue-next';
import { computed, watch } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
import Button from '@/components/ui/Button.vue';
import Dialog from '@/components/ui/Dialog.vue';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string;
}

const open = defineModel<boolean>('open', { default: false });
const props = defineProps<{
    dashboardId: number;
    visibility: 'private' | 'organization' | 'shared';
    members: Member[];
    shares: { user_id: number; permission: 'view' | 'edit' }[];
}>();

const form = useForm({ visibility: props.visibility, shares: [...props.shares] });
watch(open, (o) => {
    if (o) {
        form.visibility = props.visibility;
        form.shares = props.shares.map((s) => ({ ...s }));
        form.clearErrors();
    }
});

const choices = [
    { value: 'private', label: 'Only me', hint: 'Owners and administrators can still see it.', icon: Lock },
    { value: 'organization', label: 'Everyone in the organization', hint: 'All members can view. Only you and admins can edit.', icon: Building2 },
    { value: 'shared', label: 'Specific members', hint: 'Choose who can view or edit.', icon: Users },
] as const;

const shareOf = (id: number) => form.shares.find((s) => s.user_id === id);
function toggle(member: Member) {
    form.shares = shareOf(member.id) ? form.shares.filter((s) => s.user_id !== member.id) : [...form.shares, { user_id: member.id, permission: 'view' }];
}
function setPermission(id: number, permission: 'view' | 'edit') {
    form.shares = form.shares.map((s) => (s.user_id === id ? { ...s, permission } : s));
}
const canEdit = (m: Member) => m.role !== 'viewer';
const selectedCount = computed(() => form.shares.length);

function submit() {
    form.put(route('dashboards.share', { dashboard: props.dashboardId }), { preserveScroll: true, onSuccess: () => (open.value = false) });
}
</script>

<template>
    <Dialog v-model:open="open" title="Share dashboard" description="Shared dashboards show the same live data to everyone who can open them." size="md">
        <form id="share-form" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <div class="flex flex-col overflow-hidden rounded-md border border-border-strong" role="radiogroup" aria-label="Who can see this dashboard">
                <label
                    v-for="(c, i) in choices"
                    :key="c.value"
                    :class="cn('flex cursor-pointer items-start gap-3 px-3 py-2.5', i > 0 && 'border-t border-border', form.visibility === c.value ? 'bg-accent-subtle' : 'hover:bg-surface-hover')"
                >
                    <input v-model="form.visibility" type="radio" name="visibility" :value="c.value" class="mt-1 size-4 accent-[var(--accent)]" />
                    <component :is="c.icon" class="mt-0.5 size-4 text-secondary" aria-hidden="true" />
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ c.label }}</span>
                        <span class="block text-xs text-secondary">{{ c.hint }}</span>
                    </span>
                </label>
            </div>

            <div v-if="form.visibility === 'shared'">
                <p class="mb-2 text-sm font-medium text-ink">Members <span class="num font-normal text-secondary">{{ selectedCount }} selected</span></p>
                <ul class="max-h-64 divide-y divide-border overflow-y-auto rounded-md border border-border">
                    <li v-for="m in members" :key="m.id" class="flex items-center gap-3 px-3 py-2">
                        <input :id="`share-${m.id}`" type="checkbox" class="size-4 accent-[var(--accent)]" :checked="Boolean(shareOf(m.id))" @change="toggle(m)" />
                        <Avatar :name="m.name" size="sm" />
                        <label :for="`share-${m.id}`" class="min-w-0 flex-1 cursor-pointer">
                            <span class="block truncate text-sm text-ink">{{ m.name }}</span>
                            <span class="block truncate text-xs text-secondary">{{ m.email }} · {{ m.role }}</span>
                        </label>
                        <select
                            v-if="shareOf(m.id)"
                            :value="shareOf(m.id)!.permission"
                            class="h-7 rounded-md border border-border-strong bg-surface px-1.5 text-xs"
                            :aria-label="`Permission for ${m.name}`"
                            @change="setPermission(m.id, ($event.target as HTMLSelectElement).value as 'view' | 'edit')"
                        >
                            <option value="view">Can view</option>
                            <option value="edit" :disabled="!canEdit(m)">{{ canEdit(m) ? 'Can edit' : 'Can edit (not for viewers)' }}</option>
                        </select>
                    </li>
                    <li v-if="!members.length" class="px-3 py-4 text-sm text-secondary">No other members yet. Invite people from Settings → Members.</li>
                </ul>
                <p v-if="form.errors.shares" class="mt-1.5 text-xs text-negative" role="alert">{{ form.errors.shares }}</p>
            </div>
        </form>
        <template #footer>
            <Button @click="open = false">Cancel</Button>
            <Button type="submit" form="share-form" variant="primary" :loading="form.processing">Save sharing</Button>
        </template>
    </Dialog>
</template>
