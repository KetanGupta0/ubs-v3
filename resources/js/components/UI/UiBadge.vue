<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** Semantic tone, not a colour name, so status meaning stays consistent. */
    tone: { type: String, default: 'neutral' },
    size: { type: String, default: 'md' },
    /** Leading status dot. */
    dot: { type: Boolean, default: false },
});

const tones = {
    neutral: 'bg-ink-100 text-ink-700 dark:bg-ink-800 dark:text-ink-200',
    brand: 'bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300',
    accent: 'bg-accent-50 text-accent-700 dark:bg-accent-900/40 dark:text-accent-300',
    success: 'bg-green-50 text-green-700 dark:bg-green-950/60 dark:text-green-300',
    warning: 'bg-amber-50 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
    danger: 'bg-red-50 text-red-700 dark:bg-red-950/60 dark:text-red-300',
};

const dots = {
    neutral: 'bg-ink-400',
    brand: 'bg-brand-500',
    accent: 'bg-accent-500',
    success: 'bg-signal-500',
    warning: 'bg-warn-500',
    danger: 'bg-danger-500',
};

const toneClass = computed(() => tones[props.tone] ?? tones.neutral);
const dotClass = computed(() => dots[props.tone] ?? dots.neutral);
</script>

<template>
    <span
        :class="[
            'inline-flex items-center gap-1.5 rounded-full font-medium whitespace-nowrap',
            size === 'sm' ? 'px-2 py-0.5 text-[0.68rem]' : 'px-2.5 py-1 text-xs',
            toneClass,
        ]"
    >
        <span v-if="dot" :class="['h-1.5 w-1.5 rounded-full', dotClass]" aria-hidden="true" />
        <slot />
    </span>
</template>
