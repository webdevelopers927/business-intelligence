<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';

defineProps<{
    token: string;
    invitation: { organization: string | null; email: string; role: string; expires_at: string } | null;
    emailMatches: boolean;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const form = useForm({});
</script>

<template>
    <AuthLayout v-if="!invitation" title="Invitation unavailable">
        <p class="text-sm text-secondary">This invitation link is invalid, was already used, or has expired. Ask an administrator to send a new one.</p>
        <Button :href="route('app')" class="mt-4">Go to ORBIT</Button>
    </AuthLayout>

    <AuthLayout v-else :title="`Join ${invitation.organization}`" :description="`You've been invited as ${invitation.role}.`">
        <dl class="grid grid-cols-[96px_1fr] gap-y-2 text-sm">
            <dt class="text-secondary">Invited email</dt>
            <dd class="text-ink">{{ invitation.email }}</dd>
            <dt class="text-secondary">Role</dt>
            <dd class="text-ink">{{ invitation.role }}</dd>
            <dt class="text-secondary">Expires</dt>
            <dd class="text-ink">{{ formatDate(invitation.expires_at) }}</dd>
        </dl>

        <div class="mt-5 border-t border-border pt-5">
            <template v-if="!user">
                <p class="mb-3 text-sm text-secondary">Sign in or create an account with <span class="text-ink">{{ invitation.email }}</span> to accept.</p>
                <div class="flex gap-2">
                    <Button variant="primary" :href="route('register', { email: invitation.email })">Create account</Button>
                    <Button :href="route('login')">Sign in</Button>
                </div>
            </template>
            <template v-else-if="!emailMatches">
                <p class="text-sm text-secondary" role="alert">
                    You're signed in as <span class="text-ink">{{ user.email }}</span>, but this invitation is for
                    <span class="text-ink">{{ invitation.email }}</span>.
                </p>
                <Button class="mt-3" @click="router.post(route('logout'))">Sign out and switch account</Button>
            </template>
            <template v-else>
                <p v-if="page.props.errors.invitation" class="mb-3 text-sm text-negative" role="alert">{{ page.props.errors.invitation }}</p>
                <Button variant="primary" size="lg" class="w-full" :loading="form.processing" @click="form.post(route('invitations.accept', { token }))">
                    Accept and join
                </Button>
            </template>
        </div>

        <template #footer>
            <Link :href="route('app')" class="hover:text-ink">Not now</Link>
        </template>
    </AuthLayout>
</template>
