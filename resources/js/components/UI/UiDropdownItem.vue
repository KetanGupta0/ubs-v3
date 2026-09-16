<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    as: { type: String, default: null },
    danger: { type: Boolean, default: false },
});

const component = computed(() => (props.href ? Link : 'button'));
</script>

<template>
    <component
        :is="component"
        :href="href || undefined"
        :method="href ? method : undefined"
        :as="href && method !== 'get' ? 'button' : undefined"
        :type="href ? undefined : 'button'"
        role="menuitem"
        :class="[
            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition',
            danger
                ? 'text-danger-500 hover:bg-red-50 dark:hover:bg-red-950/40'
                : 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-strong)]',
        ]"
    >
        <slot />
    </component>
</template>
