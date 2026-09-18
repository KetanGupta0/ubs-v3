<script setup>
/**
 * A trend, as SVG.
 *
 * No plotting library: this draws polylines and a crosshair, and shipping an
 * engine that can draw a hundred other things to do it would be weight every
 * page load pays for.
 *
 * The crosshair finds the x position rather than asking the reader to hit a
 * two pixel line, and one readout lists every series at that point, so nobody
 * has to land on a particular line to get its number. Every value is also in
 * the table view behind the frame, so the hover layer enhances and never gates.
 */
import { ref, computed } from 'vue';

const props = defineProps({
    /** Category labels along the bottom, one per point. */
    categories: { type: Array, default: () => [] },
    /** [{ label, color, values: [] , display?: [] }] — colours are chart tokens. */
    series: { type: Array, default: () => [] },
    height: { type: Number, default: 220 },
    /** Fill under a single series. Off for two or more: overlapping washes read as mud. */
    area: { type: Boolean, default: true },
    /** Formats a y axis tick. */
    tick: { type: Function, default: (value) => Intl.NumberFormat('en-IN').format(Math.round(value)) },
});

/* The drawing area, in user units. Width is nominal — the SVG scales. */
const WIDTH = 720;
const PAD_LEFT = 52;
const PAD_RIGHT = 16;
const PAD_TOP = 12;
const PAD_BOTTOM = 26;

const plotWidth = WIDTH - PAD_LEFT - PAD_RIGHT;
const plotHeight = computed(() => props.height - PAD_TOP - PAD_BOTTOM);

const highest = computed(() => {
    const values = props.series.flatMap((one) => one.values.map((v) => Number(v) || 0));

    return Math.max(1, ...values);
});

/** Four gridlines on clean numbers, so the axis carries what is not labelled. */
const ticks = computed(() => {
    const step = niceStep(highest.value / 4);
    const top = Math.ceil(highest.value / step) * step;

    return {
        top,
        lines: Array.from({ length: 5 }, (unused, index) => index * step).filter((value) => value <= top),
    };
});

function niceStep(rough) {
    const magnitude = 10 ** Math.floor(Math.log10(Math.max(rough, 1)));
    const scaled = rough / magnitude;

    return (scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10) * magnitude;
}

function x(index) {
    const count = Math.max(1, props.categories.length - 1);

    return PAD_LEFT + (index / count) * plotWidth;
}

function y(value) {
    return PAD_TOP + plotHeight.value - ((Number(value) || 0) / ticks.value.top) * plotHeight.value;
}

const paths = computed(() =>
    props.series.map((one) => ({
        ...one,
        line: one.values.map((value, index) => `${index === 0 ? 'M' : 'L'}${x(index)},${y(value)}`).join(' '),
        fill: [
            `M${x(0)},${PAD_TOP + plotHeight.value}`,
            ...one.values.map((value, index) => `L${x(index)},${y(value)}`),
            `L${x(one.values.length - 1)},${PAD_TOP + plotHeight.value}`,
            'Z',
        ].join(' '),
    })),
);

const fillUnder = computed(() => props.area && props.series.length === 1);

/* ------------------------------------------------------------- crosshair */

const at = ref(null);
const box = ref(null);

function track(event) {
    const rect = box.value?.getBoundingClientRect();

    if (!rect || !props.categories.length) return;

    const ratio = (event.clientX - rect.left) / rect.width;
    const position = ratio * WIDTH;
    const count = Math.max(1, props.categories.length - 1);
    const index = Math.round(((position - PAD_LEFT) / plotWidth) * count);

    at.value = Math.max(0, Math.min(props.categories.length - 1, index));
}

function step(direction) {
    const next = (at.value ?? 0) + direction;

    at.value = Math.max(0, Math.min(props.categories.length - 1, next));
}

/** Left or right of the crosshair, whichever keeps the readout on the chart. */
const readoutSide = computed(() => {
    if (at.value === null) return 'left';

    return at.value / Math.max(1, props.categories.length - 1) > 0.6 ? 'right' : 'left';
});

const readoutAt = computed(() => (at.value === null ? 0 : (x(at.value) / WIDTH) * 100));

function valueAt(one, index) {
    return one.display?.[index] ?? props.tick(one.values[index]);
}
</script>

<template>
    <div class="relative">
        <svg
            ref="box"
            :viewBox="`0 0 ${WIDTH} ${height}`"
            class="w-full touch-none"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="`Trend over ${categories.length} points`"
            tabindex="0"
            @pointermove="track"
            @pointerleave="at = null"
            @focus="at = categories.length - 1"
            @blur="at = null"
            @keydown.left.prevent="step(-1)"
            @keydown.right.prevent="step(1)"
        >
            <!-- gridlines: hairline, solid, one step off the surface -->
            <g>
                <line
                    v-for="value in ticks.lines"
                    :key="`grid-${value}`"
                    :x1="PAD_LEFT"
                    :x2="WIDTH - PAD_RIGHT"
                    :y1="y(value)"
                    :y2="y(value)"
                    stroke="var(--chart-grid)"
                    stroke-width="1"
                />
                <text
                    v-for="value in ticks.lines"
                    :key="`tick-${value}`"
                    :x="PAD_LEFT - 8"
                    :y="y(value) + 3"
                    text-anchor="end"
                    font-size="10"
                    fill="var(--chart-axis)"
                >
                    {{ tick(value) }}
                </text>
            </g>

            <!-- the crosshair sits under the marks, so it never hides one -->
            <line
                v-if="at !== null"
                :x1="x(at)"
                :x2="x(at)"
                :y1="PAD_TOP"
                :y2="PAD_TOP + plotHeight"
                stroke="var(--border-strong)"
                stroke-width="1"
            />

            <g v-for="one in paths" :key="one.label">
                <path v-if="fillUnder" :d="one.fill" :fill="one.color" fill-opacity="0.1" />
                <path
                    :d="one.line"
                    fill="none"
                    :stroke="one.color"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />

                <!-- the end marker, ringed in the surface so it stays legible -->
                <circle
                    v-if="one.values.length"
                    :cx="x(one.values.length - 1)"
                    :cy="y(one.values[one.values.length - 1])"
                    r="4"
                    :fill="one.color"
                    stroke="var(--surface)"
                    stroke-width="2"
                />

                <circle
                    v-if="at !== null"
                    :cx="x(at)"
                    :cy="y(one.values[at])"
                    r="4"
                    :fill="one.color"
                    stroke="var(--surface)"
                    stroke-width="2"
                />
            </g>

            <!--
                The pointer layer.

                An SVG only reports events over painted pixels, so without this
                the crosshair would only appear when the cursor happened to be
                on a two pixel line — which is the thing a crosshair exists to
                avoid. A transparent rectangle over the plot makes the whole
                area pointable, and the events bubble up to the handlers on the
                svg itself.
            -->
            <rect
                :x="PAD_LEFT"
                :y="PAD_TOP"
                :width="plotWidth"
                :height="plotHeight"
                fill="transparent"
            />

            <!-- category labels: first, last, and the middle one -->
            <g font-size="10" fill="var(--chart-axis)">
                <text :x="PAD_LEFT" :y="height - 6" text-anchor="start">{{ categories[0] }}</text>
                <text
                    v-if="categories.length > 2"
                    :x="PAD_LEFT + plotWidth / 2"
                    :y="height - 6"
                    text-anchor="middle"
                >
                    {{ categories[Math.floor(categories.length / 2)] }}
                </text>
                <text
                    v-if="categories.length > 1"
                    :x="WIDTH - PAD_RIGHT"
                    :y="height - 6"
                    text-anchor="end"
                >
                    {{ categories[categories.length - 1] }}
                </text>
            </g>
        </svg>

        <!-- ------------------------------------------------------ readout -->
        <div
            v-if="at !== null"
            data-chart-readout
            class="pointer-events-none absolute top-2 z-10 min-w-[8rem] rounded-lg border px-2.5 py-2 text-xs shadow-[var(--shadow-pop)]"
            :style="{
                background: 'var(--surface-raised)',
                borderColor: 'var(--border-subtle)',
                left: readoutSide === 'left' ? `calc(${readoutAt}% + 10px)` : undefined,
                right: readoutSide === 'right' ? `calc(${100 - readoutAt}% + 10px)` : undefined,
            }"
        >
            <p class="mb-1 font-medium" style="color: var(--text-muted)">{{ categories[at] }}</p>

            <p
                v-for="one in series"
                :key="one.label"
                class="flex items-center justify-between gap-3"
            >
                <span class="flex items-center gap-1.5" style="color: var(--text-muted)">
                    <span
                        class="inline-block h-[2px] w-3 rounded-full"
                        :style="{ background: one.color }"
                        aria-hidden="true"
                    />
                    {{ one.label }}
                </span>
                <span class="font-semibold tnum" style="color: var(--text-strong)">
                    {{ valueAt(one, at) }}
                </span>
            </p>
        </div>
    </div>
</template>
