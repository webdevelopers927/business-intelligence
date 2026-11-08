<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/route';

const props = defineProps<{ token: string; email?: string | null }>();

const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });

function submit() {
    form.post(route('password.update'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <AuthLayout title="Choose a new password">
        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.email" type="email" autocomplete="username" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>
            <FormField id="password" label="New password" :error="form.errors.password" hint="At least 8 characters." required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.password" type="password" autocomplete="new-password" autofocus required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>
            <FormField id="password_confirmation" label="Confirm new password" :error="form.errors.password_confirmation" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.password_confirmation" type="password" autocomplete="new-password" required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>
            <Button type="submit" variant="primary" size="lg" class="w-full" :loading="form.processing">Reset password</Button>
        </form>
    </AuthLayout>
</template>
