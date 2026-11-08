<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/route';

defineProps<{ status?: string | null; canResetPassword: boolean }>();

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post(route('login'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <AuthLayout title="Sign in to ORBIT" description="Use the email address you registered with.">
        <p v-if="status" class="mb-4 rounded-md bg-positive-subtle px-3 py-2 text-sm text-positive" role="status">{{ status }}</p>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.email" type="email" autocomplete="username" autofocus required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <FormField id="password" label="Password" :error="form.errors.password" required>
                <template #label-end>
                    <Link v-if="canResetPassword" :href="route('password.request')" class="text-xs text-accent-ink hover:underline">Forgot password?</Link>
                </template>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.password" type="password" autocomplete="current-password" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <label class="flex items-center gap-2 text-sm text-secondary select-none">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded-sm border-border-strong accent-[var(--accent)]" />
                Keep me signed in on this device
            </label>

            <Button type="submit" variant="primary" size="lg" class="mt-1 w-full" :loading="form.processing">Sign in</Button>
        </form>

        <template #footer>
            New to ORBIT?
            <Link :href="route('register')" class="font-medium text-accent-ink hover:underline">Create an account</Link>
        </template>
    </AuthLayout>
</template>
