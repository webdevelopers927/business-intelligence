<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import SettingsSection from '@/components/ui/SettingsSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDate } from '@/lib/format';
import { route } from '@/lib/route';
import type { Option } from '@/types/domain';

interface OrganizationSettings {
    name: string;
    slug: string;
    industry: string;
    size: string;
    timezone: string;
    currency: string;
    fiscal_year_start_month: number;
    created_at: string | null;
}

const props = defineProps<{ organization: OrganizationSettings; industries: Option[]; sizes: Option[]; timezones: string[] }>();

const form = useForm({
    name: props.organization.name,
    industry: props.organization.industry,
    size: props.organization.size,
    timezone: props.organization.timezone,
    currency: props.organization.currency,
    fiscal_year_start_month: props.organization.fiscal_year_start_month,
});

const timezoneOptions = computed(() => props.timezones.map((tz) => ({ value: tz, label: tz.replaceAll('_', ' ') })));
const months = Array.from({ length: 12 }, (_, i) => ({
    value: String(i + 1),
    label: new Intl.DateTimeFormat('en-US', { month: 'long' }).format(new Date(2026, i, 1)),
}));
const fiscalMonth = computed({
    get: () => String(form.fiscal_year_start_month),
    set: (v: string) => (form.fiscal_year_start_month = Number(v)),
});

function submit() {
    form.patch(route('settings.organization.update'), { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Organization" :breadcrumbs="[{ label: 'Settings' }]" width="settings">
        <form novalidate @submit.prevent="submit">
            <SettingsSection title="Profile" description="How your organization appears to members and on exported reports.">
                <div class="grid max-w-[520px] gap-4">
                    <FormField id="name" label="Name" :error="form.errors.name" required>
                        <template #default="{ id, invalid, describedBy }">
                            <Input :id="id" v-model="form.name" maxlength="120" :invalid="invalid" :aria-describedby="describedBy" />
                        </template>
                    </FormField>
                    <FormField id="slug" label="URL identifier" hint="Used in links to this organization. Set at creation.">
                        <template #default="{ id, describedBy }">
                            <Input :id="id" :model-value="organization.slug" disabled class="font-mono text-xs" :aria-describedby="describedBy" />
                        </template>
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="industry" label="Industry" :error="form.errors.industry" required>
                            <template #default="{ id, invalid }">
                                <NativeSelect :id="id" v-model="form.industry" :options="industries" :invalid="invalid" />
                            </template>
                        </FormField>
                        <FormField id="size" label="Business size" :error="form.errors.size" required>
                            <template #default="{ id, invalid }">
                                <NativeSelect :id="id" v-model="form.size" :options="sizes" :invalid="invalid" />
                            </template>
                        </FormField>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection title="Regional" description="Analytics group dates by this timezone and format money in this currency.">
                <div class="grid max-w-[520px] gap-4">
                    <FormField id="timezone" label="Timezone" :error="form.errors.timezone" required>
                        <template #default="{ id, invalid }">
                            <NativeSelect :id="id" v-model="form.timezone" :options="timezoneOptions" :invalid="invalid" />
                        </template>
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="currency" label="Reporting currency" :error="form.errors.currency" hint="ISO 4217 code, e.g. PKR. Changes how amounts are labeled; values are not converted." required>
                            <template #default="{ id, invalid, describedBy }">
                                <Input
                                    :id="id"
                                    v-model="form.currency"
                                    maxlength="3"
                                    class="font-mono uppercase"
                                    :invalid="invalid"
                                    :aria-describedby="describedBy"
                                    @input="form.currency = form.currency.toUpperCase()"
                                />
                            </template>
                        </FormField>
                        <FormField id="fiscal" label="Fiscal year starts" :error="form.errors.fiscal_year_start_month" required>
                            <template #default="{ id, invalid }">
                                <NativeSelect :id="id" v-model="fiscalMonth" :options="months" :invalid="invalid" />
                            </template>
                        </FormField>
                    </div>
                </div>
            </SettingsSection>

            <div class="flex items-center justify-between gap-4 border-t border-border pt-4">
                <p class="text-xs text-secondary">Created {{ formatDate(organization.created_at) }}</p>
                <div class="flex items-center gap-2">
                    <Button v-if="form.isDirty" variant="ghost" @click="form.reset()">Discard changes</Button>
                    <Button type="submit" variant="primary" :disabled="!form.isDirty" :loading="form.processing">Save changes</Button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
