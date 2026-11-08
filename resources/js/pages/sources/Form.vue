<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileSpreadsheet, Globe, Server } from 'lucide-vue-next';
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import Input from '@/components/ui/Input.vue';
import SettingsSection from '@/components/ui/SettingsSection.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { DataSource, DataSourceType } from '@/types/data';
import type { Option } from '@/types/domain';

const props = defineProps<{ source: DataSource | null; types: Option<DataSourceType>[] }>();

const isEdit = computed(() => props.source !== null);
const urlPlaceholder = 'https://api.example.com/v1/orders';
const defaultPorts: Partial<Record<DataSourceType, number>> = { mysql: 3306, postgres: 5432 };

const form = useForm({
    name: props.source?.name ?? '',
    type: (props.source?.type ?? 'csv') as DataSourceType,
    description: props.source?.description ?? '',
    config: {
        host: props.source?.config.host ?? '',
        port: props.source?.config.port ?? 3306,
        database: props.source?.config.database ?? '',
        username: props.source?.config.username ?? '',
        table: props.source?.config.table ?? '',
        url: props.source?.config.url ?? '',
        auth_header: props.source?.config.auth_header ?? '',
        records_path: props.source?.config.records_path ?? '',
    },
    secret: { password: '', token: '' },
});

const isDatabase = computed(() => form.type === 'mysql' || form.type === 'postgres');
const isRest = computed(() => form.type === 'rest');
const isFile = computed(() => form.type === 'csv' || form.type === 'xlsx');

const typeIcon = (t: DataSourceType) => (t === 'csv' || t === 'xlsx' ? FileSpreadsheet : t === 'rest' ? Globe : Server);
const typeHint: Record<DataSourceType, string> = {
    csv: 'Upload comma, semicolon or tab separated files.',
    xlsx: 'Upload Excel workbooks (first sheet).',
    mysql: 'Read-only connection to a MySQL table or view.',
    postgres: 'Read-only connection to a PostgreSQL table or view.',
    rest: 'Pull JSON records from an HTTP API.',
};

function chooseType(t: DataSourceType) {
    form.type = t;
    if (defaultPorts[t]) form.config.port = defaultPorts[t]!;
}

function submit() {
    if (isEdit.value && props.source) {
        form.patch(route('sources.update', { source: props.source.id }));
    } else {
        form.post(route('sources.store'));
    }
}

const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];
</script>

<template>
    <AppLayout
        :title="isEdit ? `Edit ${source?.name}` : 'Add data source'"
        :breadcrumbs="[{ label: 'Data sources', href: route('sources.index') }]"
        width="settings"
    >
        <form novalidate @submit.prevent="submit">
            <SettingsSection v-if="!isEdit" title="Source type" description="How data reaches ORBIT. The type can't be changed later.">
                <div class="grid gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Source type">
                    <button
                        v-for="t in types"
                        :key="t.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.type === t.value"
                        :class="
                            cn(
                                'flex items-start gap-3 rounded-md border px-3 py-2.5 text-left transition-colors duration-[120ms]',
                                form.type === t.value ? 'border-accent bg-accent-subtle' : 'border-border-strong bg-surface hover:bg-surface-hover',
                            )
                        "
                        @click="chooseType(t.value)"
                    >
                        <component :is="typeIcon(t.value)" :class="cn('mt-0.5 size-4 shrink-0', form.type === t.value ? 'text-accent' : 'text-muted')" aria-hidden="true" />
                        <span>
                            <span class="block text-sm font-medium text-ink">{{ t.label }}</span>
                            <span class="block text-xs text-secondary">{{ typeHint[t.value] }}</span>
                        </span>
                    </button>
                </div>
            </SettingsSection>

            <SettingsSection title="Details" description="Shown in the sources list and on every import that uses this source.">
                <div class="grid max-w-[480px] gap-4">
                    <FormField id="name" label="Name" :error="form.errors.name" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="form.name" maxlength="120" :invalid="invalid" /></template>
                    </FormField>
                    <FormField id="description" label="Description" :error="form.errors.description">
                        <template #default="{ id, invalid }"><Input :id="id" v-model="form.description" maxlength="500" :invalid="invalid" /></template>
                    </FormField>
                </div>
            </SettingsSection>

            <SettingsSection v-if="isDatabase" title="Connection" description="ORBIT opens a read-only session with a 5-second timeout. Private network hosts are blocked unless an administrator allow-lists them.">
                <div class="grid max-w-[480px] gap-4">
                    <div class="grid gap-4 sm:grid-cols-[1fr_96px]">
                        <FormField id="host" label="Host" :error="err('config.host')" required>
                            <template #default="{ id, invalid }"><Input :id="id" v-model="form.config.host" class="font-mono text-xs" autocomplete="off" :invalid="invalid" /></template>
                        </FormField>
                        <FormField id="port" label="Port" :error="err('config.port')" required>
                            <template #default="{ id, invalid }"><Input :id="id" v-model.number="form.config.port" type="number" class="num" :invalid="invalid" /></template>
                        </FormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="database" label="Database" :error="err('config.database')" required>
                            <template #default="{ id, invalid }"><Input :id="id" v-model="form.config.database" class="font-mono text-xs" :invalid="invalid" /></template>
                        </FormField>
                        <FormField id="table" label="Table or view" :error="err('config.table')" hint="Synchronization reads from this object.">
                            <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.config.table" class="font-mono text-xs" :invalid="invalid" :aria-describedby="describedBy" /></template>
                        </FormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="username" label="Username" :error="err('config.username')" hint="Use an account with read-only grants." required>
                            <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.config.username" autocomplete="off" :invalid="invalid" :aria-describedby="describedBy" /></template>
                        </FormField>
                        <FormField
                            id="password"
                            label="Password"
                            :error="err('secret.password')"
                            :hint="source?.has_credentials ? 'Stored encrypted. Leave blank to keep it.' : 'Stored encrypted; never shown again.'"
                            :required="!source?.has_credentials"
                        >
                            <template #default="{ id, invalid, describedBy }">
                                <Input :id="id" v-model="form.secret.password" type="password" autocomplete="new-password" :placeholder="source?.has_credentials ? '••••••••' : ''" :invalid="invalid" :aria-describedby="describedBy" />
                            </template>
                        </FormField>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection v-if="isRest" title="Endpoint" description="ORBIT sends GET requests only, with a 15-second timeout. Private network hosts are blocked unless allow-listed.">
                <div class="grid max-w-[480px] gap-4">
                    <FormField id="url" label="URL" :error="err('config.url')" required>
                        <template #default="{ id, invalid }"><Input :id="id" v-model="form.config.url" type="url" :placeholder="urlPlaceholder" class="font-mono text-xs" :invalid="invalid" /></template>
                    </FormField>
                    <FormField id="records_path" label="Records path" :error="err('config.records_path')" hint="Where the list of records sits in the JSON, e.g. data or result.items. Leave blank if the response is the list.">
                        <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.config.records_path" class="font-mono text-xs" :invalid="invalid" :aria-describedby="describedBy" /></template>
                    </FormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField id="auth_header" label="Auth header" :error="err('config.auth_header')" hint="Default: Authorization (sent as Bearer).">
                            <template #default="{ id, invalid, describedBy }"><Input :id="id" v-model="form.config.auth_header" placeholder="Authorization" class="font-mono text-xs" :invalid="invalid" :aria-describedby="describedBy" /></template>
                        </FormField>
                        <FormField id="token" label="API token" :error="err('secret.token')" :hint="source?.has_credentials ? 'Stored encrypted. Leave blank to keep it.' : 'Optional. Stored encrypted.'">
                            <template #default="{ id, invalid, describedBy }">
                                <Input :id="id" v-model="form.secret.token" type="password" autocomplete="off" :placeholder="source?.has_credentials ? '••••••••' : ''" :invalid="invalid" :aria-describedby="describedBy" />
                            </template>
                        </FormField>
                    </div>
                </div>
            </SettingsSection>

            <SettingsSection v-if="isFile && !isEdit" title="How it works" description="">
                <p class="max-w-[480px] text-sm text-secondary">
                    After saving, upload files from the source page or the Imports screen. Each upload is mapped to a dataset, validated
                    row by row and tracked in the import history.
                </p>
            </SettingsSection>

            <div class="flex justify-end gap-2 border-t border-border pt-4">
                <Button :href="source ? route('sources.show', { source: source.id }) : route('sources.index')">Cancel</Button>
                <Button type="submit" variant="primary" :loading="form.processing">{{ isEdit ? 'Save changes' : 'Create source' }}</Button>
            </div>
        </form>
    </AppLayout>
</template>
