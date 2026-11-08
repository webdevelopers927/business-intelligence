<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/route';

defineProps<{ status?: string | null }>();

const form = useForm({ email: '' });
</script>

<template>
    <AuthLayout title="Reset your password" description="We'll email you a link to choose a new password.">
        <p v-if="status" class="mb-4 rounded-md bg-positive-subtle px-3 py-2 text-sm text-positive" role="status">{{ status }}</p>

        <form class="flex flex-col gap-4" novalidate @submit.prevent="form.post(route('password.email'))">
            <FormField id="email" label="Email" :error="form.errors.email" required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.email" type="email" autocomplete="username" autofocus required :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>
            <Button type="submit" variant="primary" size="lg" class="w-full" :loading="form.processing">Email reset link</Button>
        </form>

        <template #footer>
            <Link :href="route('login')" class="font-medium text-accent-ink hover:underline">Back to sign in</Link>
        </template>
    </AuthLayout>
</template>
