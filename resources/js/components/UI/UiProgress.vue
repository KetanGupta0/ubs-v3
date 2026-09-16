<script setup>
/**
 * A progress bar that also says what it means.
 *
 * The label is part of the component rather than left to each call site,
 * because a bare bar is a shape: nobody can tell 62% from 58% by looking.
 */
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    label: { type: String, default: null },
    /** 'brand', 'success', 'warning', 'danger' */
    tone: { type: String, default: 'brand' },
    size: { type: String, default: 'md' },
    showValue: { type: Boolean, default: true },
});

const clamped = computed(() => Math.max(0, Math.min(100, Math.round(props.value ?? 0))));

const tones = {
    brand: 'var(--color-brand-500)',
    success: 'var(--color-signal-500)',
    warning: 'var(--color-warn-500)',
    danger: 'var(--color-danger-500)',
};

const heights = { sm: 'h-1.5', md: 'h-2', lg: 'h-2.5' };
</script>

<template>
    <div>
        <div v-if="label || showValue" class="mb-1.5 flex items-baseline justify-between gap-3">
            <span v-if="label" class="text-xs font-medium" style="color: var(--text-muted)">{{ label }}</span>
            <span v-if="showValue" class="text-xs font-semibold tnum" style="color: var(--text-strong)">
                {{ clamped }}%
            </span>
        </div>

        <div
            class="w-full overflow-hidden rounded-full bg-[var(--surface-sunken)]"
            :class="heights[size] ?? heights.md"
            role="progressbar"
            :aria-valuenow="clamped"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="label || undefined"
        >
            <div
                class="h-full rounded-full transition-[width] duration-[var(--duration-slow)] ease-[var(--ease-out-expo)]"
                :style="{ width: `${clamped}%`, background: tones[tone] ?? tones.brand }"
            />
        </div>
    </div>
</template>
