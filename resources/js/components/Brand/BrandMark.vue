<script setup>
/**
 * The Unboundbyte mark.
 *
 * A "U" whose left arm and base are one solid stroke, while the right arm
 * dissolves upward into two detached blocks. Bound structure on one side,
 * data breaking free on the other, which is the name made visible.
 *
 * The gradient id is per instance, otherwise two marks on one page would
 * collide and the second would render with the first one's fill.
 */
import { computed, useId } from 'vue';

const props = defineProps({
    size: { type: [Number, String], default: 32 },
    /** 'gradient' for brand colour, 'current' to inherit the text colour. */
    variant: { type: String, default: 'gradient' },
});

const uid = useId();
const gradientId = computed(() => `ub-mark-${uid}`);
const paint = computed(() =>
    props.variant === 'gradient' ? `url(#${gradientId.value})` : 'currentColor',
);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 -5.5 64 64"
        fill="none"
        role="img"
        aria-label="Unboundbyte"
        class="shrink-0"
    >
        <defs v-if="variant === 'gradient'">
            <linearGradient :id="gradientId" x1="8" y1="58" x2="58" y2="4" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#4338CA" />
                <stop offset="0.55" stop-color="#4F46E5" />
                <stop offset="1" stop-color="#06B6D4" />
            </linearGradient>
        </defs>

        <!-- Bound: the solid arm and base. -->
        <path
            d="M16 8 L16 36 A16 16 0 0 0 48 36 L48 31"
            :stroke="paint"
            stroke-width="11"
            stroke-linecap="butt"
        />

        <!-- Unbound: the right arm breaking into ascending bytes. -->
        <rect x="42.5" y="15" width="11" height="9" rx="1.5" :fill="paint" />
        <rect
            x="47"
            y="1"
            width="11"
            height="9"
            rx="1.5"
            :fill="variant === 'gradient' ? '#06B6D4' : 'currentColor'"
        />
    </svg>
</template>
