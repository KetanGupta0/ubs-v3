<script setup>
defineProps({
    padding: { type: String, default: 'p-5 sm:p-6' },
    /** Lift on hover. Use for cards that are links. */
    interactive: { type: Boolean, default: false },
    as: { type: String, default: 'div' },
});
</script>

<template>
    <component
        :is="as"
        :class="[
            'rounded-[var(--radius-card)] border bg-[var(--surface)] shadow-[var(--shadow-card)]',
            interactive &&
                'transition-[transform,box-shadow,border-color] duration-[var(--duration-base)] ease-[var(--ease-out-expo)] hover:-translate-y-1 hover:shadow-[var(--shadow-pop)] hover:border-brand-300 dark:hover:border-brand-700',
        ]"
        style="border-color: var(--border-subtle)"
    >
        <header
            v-if="$slots.header"
            class="border-b px-5 py-4 sm:px-6"
            style="border-color: var(--border-subtle)"
        >
            <slot name="header" />
        </header>

        <div :class="$slots.header || $slots.footer ? 'px-5 py-5 sm:px-6' : padding">
            <slot />
        </div>

        <footer
            v-if="$slots.footer"
            class="border-t px-5 py-4 sm:px-6"
            style="border-color: var(--border-subtle)"
        >
            <slot name="footer" />
        </footer>
    </component>
</template>
