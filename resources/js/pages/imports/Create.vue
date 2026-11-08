<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileSpreadsheet, Upload, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import Button from '@/components/ui/Button.vue';
import FormField from '@/components/ui/FormField.vue';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import ImportSteps from '@/components/imports/ImportSteps.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatNumber } from '@/lib/format';
import { route } from '@/lib/route';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/domain';

const props = defineProps<{
    datasets: Option[];
    sources: Option[];
    defaults: { dataset_type: string | null; data_source_id: string | null };
    maxUploadMb: number;
}>();

const form = useForm<{ file: File | null; dataset_type: string; data_source_id: string }>({
    file: null,
    dataset_type: props.defaults.dataset_type ?? '',
    data_source_id: props.defaults.data_source_id ?? '',
});

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);
const clientError = ref('');

const fileSize = computed(() => (form.file ? `${formatNumber(form.file.size / 1024, 0)} KB` : ''));

function pick(file: File | undefined) {
    clientError.value = '';
    if (!file) return;
    if (!/\.(csv|xlsx)$/i.test(file.name)) {
        clientError.value = 'Choose a .csv or .xlsx file.';
        return;
    }
    if (file.size > props.maxUploadMb * 1024 * 1024) {
        clientError.value = `The file is larger than ${props.maxUploadMb} MB.`;
        return;
    }
    form.file = file;
}

function onDrop(e: DragEvent) {
    dragging.value = false;
    pick(e.dataTransfer?.files[0]);
}

function submit() {
    form.post(route('imports.store'), { forceFormData: true });
}

const fileError = computed(() => clientError.value || form.errors.file);
</script>

<template>
    <AppLayout title="New import" :breadcrumbs="[{ label: 'Imports', href: route('imports.index') }]" width="settings">
        <ImportSteps :current="1" class="mb-6" />

        <form class="grid gap-8" novalidate @submit.prevent="submit">
            <fieldset>
                <legend class="mb-1 text-sm font-semibold text-ink">What does the file contain?<span class="ml-0.5 text-negative" aria-hidden="true">*</span></legend>
                <p class="mb-3 text-sm text-secondary">Each dataset has its own columns. You'll match your file's columns to them next.</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="d in datasets"
                        :key="d.value"
                        :class="
                            cn(
                                'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 transition-colors duration-[120ms]',
                                form.dataset_type === d.value ? 'border-accent bg-accent-subtle' : 'border-border-strong bg-surface hover:bg-surface-hover',
                            )
                        "
                    >
                        <input v-model="form.dataset_type" type="radio" name="dataset_type" :value="d.value" class="mt-0.5 size-4 shrink-0 accent-[var(--accent)]" />
                        <span>
                            <span class="block text-sm font-medium text-ink">{{ d.label }}</span>
                            <span class="block text-xs text-secondary">{{ d.description }}</span>
                        </span>
                    </label>
                </div>
                <p v-if="form.errors.dataset_type" class="mt-1.5 text-xs text-negative" role="alert">Choose what the file contains.</p>
                <p class="mt-3 text-xs text-secondary">
                    Order matters for related data: import branches and products before the sales that reference them.
                </p>
            </fieldset>

            <div>
                <p class="mb-1 text-sm font-semibold text-ink">File<span class="ml-0.5 text-negative" aria-hidden="true">*</span></p>
                <p class="mb-3 text-sm text-secondary">CSV (comma, semicolon or tab separated) or Excel .xlsx, up to {{ maxUploadMb }} MB. The first row must contain column names.</p>

                <div
                    v-if="!form.file"
                    :class="
                        cn(
                            'flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center transition-colors duration-[120ms]',
                            dragging ? 'border-accent bg-accent-subtle' : 'border-border-strong bg-surface',
                            fileError && 'border-negative',
                        )
                    "
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <Upload class="size-5 text-muted" :stroke-width="1.5" aria-hidden="true" />
                    <p class="text-sm text-ink">Drop a file here, or <button type="button" class="font-medium text-accent-ink hover:underline" @click="input?.click()">browse</button></p>
                    <input ref="input" type="file" accept=".csv,.xlsx" class="sr-only" aria-label="Choose file" @change="pick(($event.target as HTMLInputElement).files?.[0])" />
                </div>

                <div v-else class="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
                    <FileSpreadsheet class="size-5 text-accent" :stroke-width="1.5" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ form.file.name }}</p>
                        <p class="num text-xs text-secondary">{{ fileSize }}</p>
                    </div>
                    <Button variant="ghost" size="icon-sm" aria-label="Remove file" @click="form.file = null"><X /></Button>
                </div>

                <p v-if="fileError" class="mt-1.5 text-xs text-negative" role="alert">{{ fileError }}</p>
                <progress v-if="form.progress" :value="form.progress.percentage" max="100" class="mt-2 h-1 w-full accent-[var(--accent)]">{{ form.progress.percentage }}%</progress>
            </div>

            <FormField v-if="sources.length" id="source" label="Source" :error="form.errors.data_source_id" hint="Optional. Groups this upload under a file source for history and filtering.">
                <template #default="{ id, invalid, describedBy }">
                    <NativeSelect :id="id" v-model="form.data_source_id" :options="[{ value: '', label: 'Direct upload (no source)' }, ...sources]" class="max-w-[320px]" :invalid="invalid" :aria-describedby="describedBy" />
                </template>
            </FormField>

            <div class="flex justify-end gap-2 border-t border-border pt-4">
                <Button :href="route('imports.index')">Cancel</Button>
                <Button type="submit" variant="primary" :disabled="!form.file || !form.dataset_type" :loading="form.processing">Upload and map columns</Button>
            </div>
        </form>
    </AppLayout>
</template>
