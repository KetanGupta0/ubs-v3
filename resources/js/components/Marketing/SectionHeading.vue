<script setup>
defineProps({
    eyebrow: { type: String, default: null },
    title: { type: String, required: true },
    body: { type: String, default: null },
    align: { type: String, default: 'left' },
});
</script>

<template>
    <div :class="['max-w-2xl', align === 'center' && 'mx-auto text-center']">
        <p
            v-if="eyebrow"
            class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-600 dark:text-brand-400"
        >
            {{ eyebrow }}
        </p>

        <h2 class="mt-2.5 text-2xl font-semibold leading-tight sm:text-3xl">{{ title }}</h2>

        <p v-if="body" class="mt-3 text-base leading-relaxed" style="color: var(--text-muted)">
            {{ body }}
        </p>

        <slot />
    </div>
</template>
