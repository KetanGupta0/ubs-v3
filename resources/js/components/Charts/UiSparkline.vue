<script setup>
/**
 * The line inside a stat tile.
 *
 * Deliberately bare: no axes, no grid, no hover. It says "up, flat or down"
 * beside a number that says how much, and anything more competes with the
 * number it is there to support.
 */
import { computed } from 'vue';

const props = defineProps({
    values: { type: Array, default: () => [] },
    color: { type: String, default: 'var(--chart-1)' },
    height: { type: Number, default: 32 },
    /** Fill under the line. Off for a tile that already has a lot going on. */
    area: { type: Boolean, default: true },
    label: { type: String, default: 'Trend' },
});

const WIDTH = 120;

const numbers = computed(() => props.values.map((value) => Number(value) || 0));

const points = computed(() => {
    const values = numbers.value;

    if (values.length < 2) return [];

    const highest = Math.max(...values);
    const lowest = Math.min(...values);
    const span = highest - lowest || 1;

    return values.map((value, index) => ({
        x: (index / (values.length - 1)) * WIDTH,
        y: props.height - 3 - ((value - lowest) / span) * (props.height - 6),
    }));
});

const line = computed(() =>
    points.value.map((point, index) => `${index === 0 ? 'M' : 'L'}${point.x},${point.y}`).join(' '),
);

const fill = computed(() =>
    points.value.length
        ? `${line.value} L${WIDTH},${props.height} L0,${props.height} Z`
        : '',
);

const last = computed(() => points.value[points.value.length - 1] ?? null);
</script>

<template>
    <svg
        v-if="points.length"
        :viewBox="`0 0 ${WIDTH} ${height}`"
        class="w-full"
        :style="{ height: `${height}px` }"
        role="img"
        :aria-label="label"
        preserveAspectRatio="none"
    >
        <path v-if="area" :d="fill" :fill="color" fill-opacity="0.1" />
        <path :d="line" fill="none" :stroke="color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <circle v-if="last" :cx="last.x" :cy="last.y" r="2.5" :fill="color" />
    </svg>
</template>
