<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowRight, Check } from 'lucide-vue-next';
import { computed } from 'vue';
import Button from '@/components/ui/Button.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';

interface SetupStep {
    key: string;
    title: string;
    description: string;
    done: boolean;
    href: string | null;
    action: string;
}

const props = defineProps<{ steps: SetupStep[] }>();
const page = usePage();

const completed = computed(() => props.steps.filter((s) => s.done).length);
const nextStep = computed(() => props.steps.find((s) => !s.done && s.href));
</script>

<template>
    <AppLayout title="Overview">
        <section class="max-w-[760px]" aria-labelledby="setup-heading">
            <div class="flex items-end justify-between gap-4 border-b border-border pb-3">
                <div>
                    <p class="label-instrument">Setup · {{ completed }} of {{ steps.length }} complete</p>
                    <h2 id="setup-heading" class="mt-1 text-md font-semibold text-ink">Get {{ page.props.tenant?.name }} ready for analysis</h2>
                </div>
                <div
                    class="h-1 w-32 overflow-hidden rounded-full bg-surface-sunken"
                    role="progressbar"
                    :aria-valuenow="completed"
                    :aria-valuemin="0"
                    :aria-valuemax="steps.length"
                    aria-label="Setup progress"
                >
                    <div class="h-full bg-accent transition-[width] duration-300" :style="{ width: `${(completed / steps.length) * 100}%` }" />
                </div>
            </div>

            <ol class="divide-y divide-border">
                <li v-for="(step, i) in steps" :key="step.key" class="flex items-start gap-4 py-4">
                    <span
                        :class="
                            cn(
                                'num mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                                step.done ? 'border-positive bg-positive text-white' : 'border-border-strong text-secondary',
                            )
                        "
                    >
                        <Check v-if="step.done" class="size-3.5" :stroke-width="2.5" aria-label="Done" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p :class="cn('text-sm font-medium', step.done ? 'text-secondary' : 'text-ink')">{{ step.title }}</p>
                        <p class="mt-0.5 text-sm text-secondary">{{ step.description }}</p>
                    </div>
                    <Button
                        v-if="step.href"
                        :href="step.href"
                        :variant="nextStep?.key === step.key ? 'primary' : 'ghost'"
                        size="sm"
                        class="mt-0.5"
                    >
                        {{ step.action }}
                        <ArrowRight v-if="nextStep?.key === step.key" />
                    </Button>
                </li>
            </ol>
        </section>
    </AppLayout>
</template>
