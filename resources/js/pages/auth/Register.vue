<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/route';

const props = defineProps<{ email?: string | null }>();

const form = useForm({ name: '', email: props.email ?? '', password: '', password_confirmation: '' });

function submit() {
    form.post(route('register'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout title="Create your account" description="You'll set up your organization in the next step.">
        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <FormField id="name" label="Full name" :error="form.errors.name" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.name" autocomplete="name" autofocus required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <FormField id="email" label="Work email" :error="form.errors.email" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.email" type="email" autocomplete="email" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <FormField id="password" label="Password" :error="form.errors.password" hint="At least 8 characters." required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.password" type="password" autocomplete="new-password" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <FormField id="password_confirmation" label="Confirm password" :error="form.errors.password_confirmation" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.password_confirmation" type="password" autocomplete="new-password" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>

            <Button type="submit" variant="primary" size="lg" class="mt-1 w-full" :loading="form.processing">Create account</Button>
        </form>

        <template #footer>
            Already have an account?
            <Link :href="route('login')" class="font-medium text-accent-ink hover:underline">Sign in</Link>
        </template>
    </AuthLayout>
</template>
