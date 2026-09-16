<script setup>
/**
 * The one button in the system.
 *
 * Renders as <button>, <a> or Inertia <Link> depending on what it is given,
 * so a "button" that navigates never loses its keyboard or styling behaviour.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';

const props = defineProps({
    variant: { type: String, default: 'primary' },
    size: { type: String, default: 'md' },
    type: { type: String, default: 'button' },
    href: { type: String, default: null },
    /** Inertia visit instead of a full page load. Ignored without href. */
    inertia: { type: Boolean, default: true },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
    /** Square icon only button. Pass an accessible label alongside it. */
    icon: { type: Boolean, default: false },
});

const variants = {
    primary:
        'bg-brand-600 text-white shadow-sm hover:bg-brand-700 active:bg-brand-800 ' +
        'dark:bg-brand-500 dark:hover:bg-brand-400 dark:active:bg-brand-500',
    accent:
        'bg-accent-500 text-ink-950 shadow-sm hover:bg-accent-400 active:bg-accent-600 font-semibold',
    secondary:
        'bg-[var(--surface)] text-[var(--text-strong)] border border-[var(--border-strong)] ' +
        'hover:bg-[var(--surface-sunken)] active:bg-[var(--surface-sunken)]',
    ghost: 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-strong)]',
    danger: 'bg-danger-600 text-white shadow-sm hover:bg-danger-500 active:bg-danger-600',
    subtle: 'bg-brand-50 text-brand-700 hover:bg-brand-100 dark:bg-brand-950 dark:text-brand-200 dark:hover:bg-brand-900',
};

const sizes = {
    xs: 'h-7 px-2.5 text-xs gap-1.5 rounded-md',
    sm: 'h-9 px-3.5 text-sm gap-1.5 rounded-[var(--radius-field)]',
    md: 'h-10 px-4 text-sm gap-2 rounded-[var(--radius-field)]',
    lg: 'h-12 px-6 text-base gap-2 rounded-xl',
};

const iconSizes = {
    xs: 'h-7 w-7 rounded-md',
    sm: 'h-9 w-9 rounded-[var(--radius-field)]',
    md: 'h-10 w-10 rounded-[var(--radius-field)]',
    lg: 'h-12 w-12 rounded-xl',
};

const classes = computed(() => [
    'inline-flex items-center justify-center font-medium whitespace-nowrap',
    'transition-[background-color,color,box-shadow,transform] duration-[var(--duration-fast)]',
    'active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50',
    variants[props.variant] ?? variants.primary,
    props.icon ? iconSizes[props.size] : sizes[props.size],
    props.block && 'w-full',
]);

const component = computed(() => {
    if (!props.href) return 'button';
    return props.inertia ? Link : 'a';
});

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <component
        :is="component"
        :class="classes"
        :href="href || undefined"
        :type="href ? undefined : type"
        :disabled="href ? undefined : isDisabled"
        :aria-disabled="href && isDisabled ? 'true' : undefined"
        :aria-busy="loading ? 'true' : undefined"
    >
        <Loader2 v-if="loading" class="h-4 w-4 animate-spin" aria-hidden="true" />
        <slot v-else name="leading" />
        <slot />
        <slot name="trailing" />
    </component>
</template>
