<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    as: { type: String, default: null },
    danger: { type: Boolean, default: false },
    /**
     * False for a link the browser has to follow itself: a download (CSV,
     * Excel, PDF) or a page outside the panel. Until November 2026 this was
     * passed and ignored, so every export on every list went through Inertia,
     * which shows a response that is not a page in a pop up: the PDF arrived
     * as its raw bytes on screen.
     */
    inertia: { type: Boolean, default: true },
});

const plain = computed(() => props.href && !props.inertia);
const component = computed(() => (props.href ? (plain.value ? 'a' : Link) : 'button'));
</script>

<template>
    <component
        :is="component"
        :href="href || undefined"
        :method="href && !plain ? method : undefined"
        :as="href && !plain && method !== 'get' ? 'button' : undefined"
        :type="href ? undefined : 'button'"
        role="menuitem"
        :class="[
            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition',
            danger
                ? 'text-danger hover:bg-red-50 dark:hover:bg-red-950/40'
                : 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)] hover:text-[var(--text-strong)]',
        ]"
    >
        <slot />
    </component>
</template>
