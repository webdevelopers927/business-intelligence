<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/domain';

const props = defineProps<{ industries: Option[]; sizes: Option[]; isAdditional: boolean }>();

const step = ref<1 | 2>(1);
const form = useForm({ name: '', industry: '', size: '' });
const stepOneError = ref<{ name?: string; industry?: string }>({});

const title = computed(() => (props.isAdditional ? 'Create another organization' : 'Set up your organization'));

function next() {
    stepOneError.value = {};
    if (form.name.trim().length < 2) {
        stepOneError.value.name = 'Enter your organization name.';
    }
    if (!form.industry) {
        stepOneError.value.industry = 'Choose the industry closest to yours.';
    }
    if (!stepOneError.value.name && !stepOneError.value.industry) {
        step.value = 2;
    }
}

function submit() {
    form.post(route('onboarding.store'), {
        onError: (errors) => {
            if (errors.name || errors.industry) {
                step.value = 1;
            }
        },
    });
}
</script>

<template>
    <AuthLayout :title="title" description="Two quick details. You can change them later in settings." width="md">
        <p class="label-instrument mb-4">Step {{ step }} of 2</p>

        <form v-if="step === 1" class="flex flex-col gap-4" novalidate @submit.prevent="next">
            <FormField id="name" label="Organization name" :error="stepOneError.name ?? form.errors.name" hint="Shown to everyone you invite." required>
                <template #default="{ id, invalid, describedBy }">
                    <Input :id="id" v-model="form.name" autocomplete="organization" autofocus maxlength="120" :invalid="invalid" :aria-describedby="describedBy" class="h-9" />
                </template>
            </FormField>
            <FormField id="industry" label="Industry" :error="stepOneError.industry ?? form.errors.industry" required>
                <template #default="{ id, invalid, describedBy }">
                    <NativeSelect :id="id" v-model="form.industry" :options="industries" placeholder="Select an industry" :invalid="invalid" :aria-describedby="describedBy" />
                </template>
            </FormField>
            <div class="mt-1 flex justify-end">
                <Button type="submit" variant="primary" size="lg">Continue</Button>
            </div>
        </form>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <fieldset>
                <legend class="mb-1.5 text-sm font-medium text-ink">
                    Business size<span class="ml-0.5 text-negative" aria-hidden="true">*</span>
                </legend>
                <p class="mb-3 text-xs text-secondary">Used to tune defaults such as dashboard density. It never limits features.</p>
                <div class="overflow-hidden rounded-md border border-border-strong" role="radiogroup">
                    <label
                        v-for="(size, i) in sizes"
                        :key="size.value"
                        :class="
                            cn(
                                'flex h-10 cursor-pointer items-center gap-3 px-3 text-sm text-ink hover:bg-surface-hover',
                                i > 0 && 'border-t border-border',
                                form.size === size.value && 'bg-accent-subtle text-accent-ink hover:bg-accent-subtle',
                            )
                        "
                    >
                        <input v-model="form.size" type="radio" name="size" :value="size.value" class="size-4 accent-[var(--accent)]" />
                        <span class="num">{{ size.label }}</span>
                    </label>
                </div>
                <p v-if="form.errors.size" class="mt-1.5 text-xs text-negative" role="alert">{{ form.errors.size }}</p>
            </fieldset>

            <div class="mt-1 flex items-center justify-between">
                <Button variant="ghost" @click="step = 1"><ArrowLeft />Back</Button>
                <Button type="submit" variant="primary" size="lg" :disabled="!form.size" :loading="form.processing">Create organization</Button>
            </div>
        </form>
    </AuthLayout>
</template>
