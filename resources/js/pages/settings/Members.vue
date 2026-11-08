<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Ellipsis, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Avatar from '@/components/ui/Avatar.vue';
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
import { formatDate, formatRelative } from '@/lib/format';
import { route } from '@/lib/route';
import type { Option, Role } from '@/types/domain';

interface MemberRow {
    id: number;
    user_id: number;
    name: string;
    email: string;
    role: Role;
    joined_at: string | null;
    is_self: boolean;
    can_manage: boolean;
}

interface InvitationRow {
    id: number;
    email: string;
    role: Role;
    invited_by: string | null;
    expires_at: string;
}

const props = defineProps<{
    members: MemberRow[];
    invitations: InvitationRow[];
    roles: Option<Role>[];
    assignableRoles: Option<Role>[];
}>();

const roleLabel = (role: Role) => props.roles.find((r) => r.value === role)?.label ?? role;
const roleTone = (role: Role) => (role === 'owner' ? 'accent' : 'neutral') as 'accent' | 'neutral';

// Invite
const inviteOpen = ref(false);
const invite = useForm({ email: '', role: 'analyst' as Role });
const selectedRoleDescription = computed(() => props.roles.find((r) => r.value === invite.role)?.description);

function sendInvite() {
    invite.post(route('settings.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            inviteOpen.value = false;
            invite.reset();
        },
    });
}

// Role change
function changeRole(member: MemberRow, role: Role) {
    if (role !== member.role) {
        router.patch(route('settings.members.update', { membership: member.id }), { role }, { preserveScroll: true });
    }
}

// Removal
const removing = ref<MemberRow | null>(null);
const removeBusy = ref(false);
function confirmRemove() {
    if (!removing.value) return;
    removeBusy.value = true;
    router.delete(route('settings.members.destroy', { membership: removing.value.id }), {
        preserveScroll: true,
        onFinish: () => {
            removeBusy.value = false;
            removing.value = null;
        },
    });
}

function revoke(invitation: InvitationRow) {
    router.delete(route('settings.invitations.destroy', { invitation: invitation.id }), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Members" :breadcrumbs="[{ label: 'Settings' }]" width="settings">
        <template #actions>
            <Button variant="primary" @click="inviteOpen = true"><UserPlus />Invite member</Button>
        </template>

        <!-- Members -->
        <section aria-labelledby="members-heading">
            <div class="flex items-baseline justify-between pb-2">
                <h2 id="members-heading" class="text-sm font-semibold text-ink">
                    Members <span class="num ml-1 font-normal text-secondary">{{ members.length }}</span>
                </h2>
            </div>
            <div class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs font-medium text-secondary">
                        <tr class="h-8">
                            <th scope="col" class="px-4 font-medium">Name</th>
                            <th scope="col" class="w-36 px-4 font-medium">Role</th>
                            <th scope="col" class="w-32 px-4 font-medium">Joined</th>
                            <th scope="col" class="w-12 px-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="member in members" :key="member.id" class="h-12 hover:bg-surface-hover">
                            <td class="px-4">
                                <div class="flex items-center gap-2.5">
                                    <Avatar :name="member.name" />
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-ink">
                                            {{ member.name }}
                                            <span v-if="member.is_self" class="ml-1 text-xs font-normal text-secondary">(you)</span>
                                        </p>
                                        <p class="truncate text-xs text-secondary">{{ member.email }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4"><Badge :tone="roleTone(member.role)">{{ roleLabel(member.role) }}</Badge></td>
                            <td class="px-4 text-xs text-secondary">{{ formatDate(member.joined_at) }}</td>
                            <td class="px-2 text-right">
                                <Menu v-if="member.can_manage" width="w-52">
                                    <template #trigger>
                                        <Button variant="ghost" size="icon-sm" :aria-label="`Actions for ${member.name}`"><Ellipsis /></Button>
                                    </template>
                                    <MenuLabel>Change role</MenuLabel>
                                    <MenuItem v-for="role in assignableRoles" :key="role.value" :disabled="role.value === member.role" @select="changeRole(member, role.value)">
                                        {{ role.label }}
                                        <span v-if="role.value === member.role" class="ml-auto text-xs text-muted">Current</span>
                                    </MenuItem>
                                    <MenuLabel separator />
                                    <MenuItem destructive @select="removing = member">Remove from organization</MenuItem>
                                </Menu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Pending invitations -->
        <section class="mt-8" aria-labelledby="invites-heading">
            <h2 id="invites-heading" class="pb-2 text-sm font-semibold text-ink">
                Pending invitations <span class="num ml-1 font-normal text-secondary">{{ invitations.length }}</span>
            </h2>
            <div v-if="invitations.length === 0" class="border-t border-border py-4 text-sm text-secondary">
                No pending invitations. Invited people appear here until they accept.
            </div>
            <div v-else class="overflow-x-auto rounded-lg border border-border bg-surface">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="bg-surface-sunken text-left text-xs text-secondary">
                        <tr class="h-8">
                            <th scope="col" class="px-4 font-medium">Email</th>
                            <th scope="col" class="w-36 px-4 font-medium">Role</th>
                            <th scope="col" class="w-32 px-4 font-medium">Expires</th>
                            <th scope="col" class="w-24 px-2"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="inv in invitations" :key="inv.id" class="h-10 hover:bg-surface-hover">
                            <td class="px-4">
                                <span class="text-ink">{{ inv.email }}</span>
                                <span v-if="inv.invited_by" class="ml-2 text-xs text-secondary">by {{ inv.invited_by }}</span>
                            </td>
                            <td class="px-4"><Badge>{{ roleLabel(inv.role) }}</Badge></td>
                            <td class="px-4 text-xs text-secondary">{{ formatRelative(inv.expires_at) }}</td>
                            <td class="px-2 text-right">
                                <Button variant="ghost-destructive" size="sm" @click="revoke(inv)">Revoke</Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Dialog v-model:open="inviteOpen" title="Invite a member" description="They'll get an email link that's valid for 7 days.">
            <form id="invite-form" class="flex flex-col gap-4" novalidate @submit.prevent="sendInvite">
                <FormField id="invite-email" label="Email" :error="invite.errors.email" required>
                    <template #default="{ id, invalid, describedBy }">
                        <Input :id="id" v-model="invite.email" type="email" autofocus :invalid="invalid" :aria-describedby="describedBy" />
                    </template>
                </FormField>
                <FormField id="invite-role" label="Role" :error="invite.errors.role" :hint="selectedRoleDescription" required>
                    <template #default="{ id, invalid, describedBy }">
                        <NativeSelect :id="id" v-model="invite.role" :options="assignableRoles" :invalid="invalid" :aria-describedby="describedBy" />
                    </template>
                </FormField>
            </form>
            <template #footer>
                <Button @click="inviteOpen = false">Cancel</Button>
                <Button type="submit" form="invite-form" variant="primary" :loading="invite.processing">Send invitation</Button>
            </template>
        </Dialog>

        <ConfirmDialog
            :open="removing !== null"
            :title="`Remove ${removing?.name ?? 'member'}?`"
            :description="`${removing?.name ?? 'This member'} will immediately lose access to every dashboard and report in this organization. Their past activity stays in the audit log.`"
            confirm-label="Remove member"
            :loading="removeBusy"
            @update:open="(o: boolean) => !o && (removing = null)"
            @confirm="confirmRemove"
        />
    </AppLayout>
</template>
