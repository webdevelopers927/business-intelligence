<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { cva, type VariantProps } from 'class-variance-authority';
import { LoaderCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Button hierarchy (DESIGN_SYSTEM §7): one `primary` per view region;
 * `secondary` for alternatives; `ghost` for low-emphasis/toolbar actions;
 * `destructive` only inside confirmation dialogs.
 */
const button = cva(
    'inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-md font-medium transition-colors duration-[120ms] select-none disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                primary: 'bg-accent text-white hover:bg-accent-hover active:bg-accent-hover',
                secondary:
                    'border border-border-strong bg-surface text-ink hover:bg-surface-hover active:bg-surface-sunken',
                ghost: 'text-secondary hover:bg-surface-hover hover:text-ink active:bg-surface-sunken',
                destructive: 'bg-negative text-white hover:brightness-95 active:brightness-90',
                'ghost-destructive': 'text-negative hover:bg-negative-subtle',
                link: 'h-auto px-0 text-accent-ink underline-offset-4 hover:underline',
            },
            size: {
                sm: 'h-7 px-2.5 text-xs',
                md: 'h-8 px-3 text-sm',
                lg: 'h-9 px-4 text-sm',
                icon: 'size-8',
                'icon-sm': 'size-7',
            },
        },
        defaultVariants: { variant: 'secondary', size: 'md' },
    },
);

type ButtonVariants = VariantProps<typeof button>;

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariants['variant'];
        size?: ButtonVariants['size'];
        href?: string;
        /** Render a plain <a> (file downloads, off-app links) instead of an Inertia Link. */
        external?: boolean;
        type?: 'button' | 'submit' | 'reset';
        loading?: boolean;
        disabled?: boolean;
        class?: string;
    }>(),
    { type: 'button', loading: false, disabled: false },
);

const classes = computed(() => cn(button({ variant: props.variant, size: props.size }), props.class));
</script>

<template>
    <a v-if="href && external && !disabled" :href="href" :class="classes">
        <slot />
    </a>
    <Link v-else-if="href && !disabled" :href="href" :class="classes">
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading || undefined">
        <LoaderCircle v-if="loading" class="animate-spin" aria-hidden="true" />
        <slot />
    </button>
</template>
