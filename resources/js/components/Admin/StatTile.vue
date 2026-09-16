<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint: { type: String, default: null },
    href: { type: String, default: null },
    tone: { type: String, default: 'neutral' },
});

const tones = {
    neutral: 'var(--text-strong)',
    brand: 'var(--color-brand-600)',
    warning: 'var(--color-warn-600)',
    danger: 'var(--color-danger-500)',
    success: 'var(--color-signal-600)',
};

const colour = computed(() => tones[props.tone] ?? tones.neutral);
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href || undefined"
        class="block rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition"
        :class="href && 'hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]'"
        style="border-color: var(--border-subtle)"
    >
        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">{{ label }}</p>
        <p class="mt-1.5 text-2xl font-semibold tnum" :style="{ color: colour }">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-xs" style="color: var(--text-muted)">{{ hint }}</p>
    </component>
</template>
