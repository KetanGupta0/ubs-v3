<script setup>
/**
 * Magnitude, as SVG bars.
 *
 * The bar is capped rather than stretched to fill its slot: a chart where the
 * bars touch is a wall, and the leftover space is what makes the shape
 * readable. The data end is rounded and the baseline end is square, so the
 * eye knows which end is the measurement.
 *
 * Stacked segments and neighbouring bars are separated by a two pixel gap in
 * the surface colour rather than by a stroke. A stroke adds ink that is not
 * data; a gap is the surface showing through.
 */
import { ref, computed } from 'vue';

const props = defineProps({
    categories: { type: Array, default: () => [] },
    /** [{ label, color, values: [], display?: [] }] */
    series: { type: Array, default: () => [] },
    stacked: { type: Boolean, default: false },
    height: { type: Number, default: 220 },
    /** Show every nth category label, so thirty days is not a smear. */
    labelEvery: { type: Number, default: 1 },
    tick: { type: Function, default: (value) => Intl.NumberFormat('en-IN').format(Math.round(value)) },
});

const WIDTH = 720;
const PAD_LEFT = 52;
const PAD_RIGHT = 12;
const PAD_TOP = 12;
const PAD_BOTTOM = 26;
const MAX_BAR = 24;
const GAP = 2;

const plotWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
const plotHeight = computed(() => props.height - PAD_TOP - PAD_BOTTOM);

const totals = computed(() =>
    props.categories.map((unused, index) =>
        props.stacked
            ? props.series.reduce((sum, one) => sum + (Number(one.values[index]) || 0), 0)
            : Math.max(...props.series.map((one) => Number(one.values[index]) || 0)),
    ),
);

const ticks = computed(() => {
    const highest = Math.max(1, ...totals.value);
    const step = niceStep(highest / 4);
    const top = Math.ceil(highest / step) * step;

    return { top, lines: Array.from({ length: 5 }, (u, i) => i * step).filter((v) => v <= top) };
});

function niceStep(rough) {
    const magnitude = 10 ** Math.floor(Math.log10(Math.max(rough, 1)));
    const scaled = rough / magnitude;

    return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
}

const slot = computed(() => plotWidth / Math.max(1, props.categories.length));

/** Capped, and never wider than the slot allows once the gaps are taken out. */
const barWidth = computed(() => {
    const perSeries = props.stacked ? 1 : props.series.length;
    const available = (slot.value * 0.7) / perSeries - GAP;

    return Math.max(3, Math.min(MAX_BAR, available));
});

function baseline() {
    return PAD_TOP + plotHeight.value;
}

function scale(value) {
    return ((Number(value) || 0) / ticks.value.top) * plotHeight.value;
}

/**
 * A rectangle with only its data end rounded.
 *
 * Four pixel radius at the top, square where it meets the baseline, so a bar
 * always reads as growing from the axis rather than floating.
 */
function barPath(left, top, width, height) {
    const radius = Math.min(4, width / 2, Math.max(0, height));

    if (height <= 0.5) return '';

    return [
        `M${left},${top + height}`,
        `L${left},${top + radius}`,
        `Q${left},${top} ${left + radius},${top}`,
        `L${left + width - radius},${top}`,
        `Q${left + width},${top} ${left + width},${top + radius}`,
        `L${left + width},${top + height}`,
        'Z',
    ].join(' ');
}

const marks = computed(() => {
    const out = [];

    props.categories.forEach((category, index) => {
        const slotLeft = PAD_LEFT + index * slot.value;

        if (props.stacked) {
            let cursor = baseline();

            props.series.forEach((one, seriesIndex) => {
                const size = scale(one.values[index]);

                if (size <= 0) return;

                // The gap comes out of the segment, so the stack still totals
                // to the right height and the surface does the separating.
                const height = Math.max(1, size - GAP);
                const top = cursor - size;

                out.push({
                    key: `${index}-${seriesIndex}`,
                    path: barPath(slotLeft + (slot.value - barWidth.value) / 2, top, barWidth.value, height),
                    color: one.color,
                    category,
                    label: one.label,
                    value: one.display?.[index] ?? props.tick(one.values[index]),
                    x: slotLeft + slot.value / 2,
                    y: top,
                });

                cursor = top;
            });

            return;
        }

        props.series.forEach((one, seriesIndex) => {
            const size = scale(one.values[index]);
            const groupWidth = props.series.length * (barWidth.value + GAP) - GAP;
            const left = slotLeft + (slot.value - groupWidth) / 2 + seriesIndex * (barWidth.value + GAP);

            out.push({
                key: `${index}-${seriesIndex}`,
                path: barPath(left, baseline() - size, barWidth.value, size),
                color: one.color,
                category,
                label: one.label,
                value: one.display?.[index] ?? props.tick(one.values[index]),
                x: left + barWidth.value / 2,
                y: baseline() - size,
                empty: size <= 0,
            });
        });
    });

    return out;
});

const hovered = ref(null);

const readout = computed(() => marks.value.find((mark) => mark.key === hovered.value) ?? null);
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${WIDTH} ${height}`"
            class="w-full"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="`Bar chart of ${categories.length} categories`"
        >
            <g>
                <line
                    v-for="value in ticks.lines"
                    :key="`grid-${value}`"
                    :x1="PAD_LEFT"
                    :x2="WIDTH - PAD_RIGHT"
                    :y1="baseline() - scale(value)"
                    :y2="baseline() - scale(value)"
                    stroke="var(--chart-grid)"
                    stroke-width="1"
                />
                <text
                    v-for="value in ticks.lines"
                    :key="`tick-${value}`"
                    :x="PAD_LEFT - 8"
                    :y="baseline() - scale(value) + 3"
                    text-anchor="end"
                    font-size="10"
                    fill="var(--chart-axis)"
                >
                    {{ tick(value) }}
                </text>
            </g>

            <g>
                <path
                    v-for="mark in marks"
                    :key="mark.key"
                    :d="mark.path"
                    :fill="mark.color"
                    :fill-opacity="hovered && hovered !== mark.key ? 0.55 : 1"
                    class="transition-[fill-opacity] duration-[var(--duration-fast)]"
                />
            </g>

            <!-- The hit target is the whole column, not the painted bar: a
                 three pixel bar is not something anybody can point at. -->
            <g>
                <rect
                    v-for="(category, index) in categories"
                    :key="`hit-${index}`"
                    :x="PAD_LEFT + index * slot"
                    :y="PAD_TOP"
                    :width="slot"
                    :height="plotHeight"
                    fill="transparent"
                    tabindex="0"
                    :aria-label="`${category}: ${series.map((one) => `${one.label} ${one.display?.[index] ?? one.values[index]}`).join(', ')}`"
                    @pointerenter="hovered = `${index}-0`"
                    @focus="hovered = `${index}-0`"
                    @pointerleave="hovered = null"
                    @blur="hovered = null"
                />
            </g>

            <g font-size="10" fill="var(--chart-axis)">
                <text
                    v-for="(category, index) in categories"
                    :key="`label-${index}`"
                    :x="PAD_LEFT + index * slot + slot / 2"
                    :y="height - 6"
                    text-anchor="middle"
                >
                    {{ index % labelEvery === 0 ? category : '' }}
                </text>
            </g>
        </svg>

        <div
            v-if="readout"
            data-chart-readout
            class="pointer-events-none absolute top-2 z-10 min-w-[7rem] rounded-lg border px-2.5 py-2 text-xs shadow-[var(--shadow-pop)]"
            :style="{
                background: 'var(--surface-raised)',
                borderColor: 'var(--border-subtle)',
                left: readout.x / WIDTH > 0.6 ? undefined : `calc(${(readout.x / WIDTH) * 100}% + 10px)`,
                right: readout.x / WIDTH > 0.6 ? `calc(${100 - (readout.x / WIDTH) * 100}% + 10px)` : undefined,
            }"
        >
            <p class="mb-1 font-medium" style="color: var(--text-muted)">{{ readout.category }}</p>

            <p
                v-for="(one, index) in series"
                :key="one.label"
                class="flex items-center justify-between gap-3"
            >
                <span class="flex items-center gap-1.5" style="color: var(--text-muted)">
                    <span
                        class="inline-block h-2 w-2 rounded-[2px]"
                        :style="{ background: one.color }"
                        aria-hidden="true"
                    />
                    {{ one.label }}
                </span>
                <span class="font-semibold tnum" style="color: var(--text-strong)">
                    {{ one.display?.[categories.indexOf(readout.category)]
                        ?? tick(one.values[categories.indexOf(readout.category)]) }}
                </span>
            </p>
        </div>
    </div>
</template>
