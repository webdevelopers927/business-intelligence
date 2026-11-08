<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SettingsSection from '@/components/ui/SettingsSection.vue';
import Toaster from '@/components/ui/Toaster.vue';
import OrbitMark from '@/components/shell/OrbitMark.vue';
import { useToasts } from '@/composables/useToasts';
import { route } from '@/lib/route';

/**
 * Account-level page (not organization-scoped), so it uses a slim header
 * instead of the tenant shell.
 */
const props = defineProps<{ profile: { name: string; email: string; theme: string }; backUrl: string | null }>();
const { push } = useToasts();

const info = useForm({ name: props.profile.name, email: props.profile.email });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function saveInfo() {
    info.put(route('user-profile-information.update'), {
        preserveScroll: true,
        onSuccess: () => push({ type: 'success', message: 'Profile updated.' }),
    });
}

function savePassword() {
    password.put(route('user-password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            password.reset();
            push({ type: 'success', message: 'Password changed.' });
        },
        onError: () => password.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Profile" />
    <div class="min-h-dvh bg-bg">
        <header class="flex h-14 items-center gap-4 border-b border-border bg-surface px-6">
            <OrbitMark />
            <Link v-if="backUrl" :href="backUrl" class="inline-flex items-center gap-1 text-sm text-secondary hover:text-ink">
                <ArrowLeft class="size-4" /> Back to workspace
            </Link>
        </header>
        <main class="mx-auto max-w-[880px] px-6 py-8">
            <h1 class="mb-6 text-lg font-semibold text-ink">Profile</h1>

            <SettingsSection title="Personal information" description="Your name appears on dashboards you own and in the activity log.">
                <form class="grid max-w-[440px] gap-4" novalidate @submit.prevent="saveInfo">
                    <FormField id="name" label="Full name" :error="info.errors.name" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="info.name" autocomplete="name" :invalid="invalid" /></template>
                    </FormField>
                    <FormField id="email" label="Email" :error="info.errors.email" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="info.email" type="email" autocomplete="email" :invalid="invalid" /></template>
                    </FormField>
                    <div><Button type="submit" variant="primary" :disabled="!info.isDirty" :loading="info.processing">Save</Button></div>
                </form>
            </SettingsSection>

            <SettingsSection title="Password" description="Use a long passphrase you don't use elsewhere.">
                <form class="grid max-w-[440px] gap-4" novalidate @submit.prevent="savePassword">
                    <FormField id="current_password" label="Current password" :error="password.errors.current_password" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="password.current_password" type="password" autocomplete="current-password" :invalid="invalid" /></template>
                    </FormField>
                    <FormField id="new_password" label="New password" :error="password.errors.password" hint="At least 8 characters." required>
                        <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="password.password" type="password" autocomplete="new-password" :invalid="invalid" :aria-describedby="describedBy" /></template>
                    </FormField>
                    <FormField id="password_confirmation" label="Confirm new password" :error="password.errors.password_confirmation" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="password.password_confirmation" type="password" autocomplete="new-password" :invalid="invalid" /></template>
                    </FormField>
                    <div><Button type="submit" variant="primary" :loading="password.processing">Change password</Button></div>
                </form>
            </SettingsSection>
        </main>
    </div>
    <Toaster />
</template>
