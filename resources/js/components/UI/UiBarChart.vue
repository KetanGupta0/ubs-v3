<script setup>
/**
 * A small bar chart, drawn as SVG.
 *
 * No charting library: this draws one series of bars, and a dependency that
 * ships a whole plotting engine to do that is weight the client pays for on
 * every page load.
 *
 * Every bar carries its own accessible label, so the chart is readable without
 * hovering and without sight.
 */
import { computed } from 'vue';

const props = defineProps({
    /** [{ label, value, display? }] */
    data: { type: Array, default: () => [] },
    height: { type: Number, default: 120 },
    tone: { type: String, default: 'var(--color-brand-500)' },
    /** Show every nth label, so a thirty day series does not become a smear. */
    labelEvery: { type: Number, default: 1 },
});

const max = computed(() => Math.max(1, ...props.data.map((point) => Number(point.value) || 0)));

const bars = computed(() =>
    props.data.map((point, index) => {
        const value = Number(point.value) || 0;

        return {
            ...point,
            index,
            percent: (value / max.value) * 100,
            showLabel: index % props.labelEvery === 0,
        };
    }),
);

const isEmpty = computed(() => props.data.every((point) => !Number(point.value)));
</script>

<template>
    <div>
        <div
            class="flex items-end gap-[3px]"
            :style="{ height: `${height}px` }"
            role="img"
            :aria-label="`Bar chart of ${data.length} values`"
        >
            <div
                v-for="bar in bars"
                :key="bar.index"
                class="group relative flex-1"
                :style="{ height: '100%' }"
            >
                <div class="absolute inset-x-0 bottom-0 flex flex-col justify-end" style="height: 100%">
                    <div
                        class="w-full rounded-t-[3px] transition-[height] duration-[var(--duration-slow)]"
                        :style="{
                            height: `${Math.max(bar.percent, bar.percent > 0 ? 3 : 1)}%`,
                            background: bar.percent > 0 ? tone : 'var(--border-subtle)',
                        }"
                    />
                </div>

                <span class="sr-only">{{ bar.label }}: {{ bar.display ?? bar.value }}</span>

                <div
                    class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1.5 hidden -translate-x-1/2 whitespace-nowrap
                           rounded-md px-2 py-1 text-[0.7rem] shadow-[var(--shadow-pop)] group-hover:block"
                    style="background: var(--surface-raised); color: var(--text-strong); border: 1px solid var(--border-subtle)"
                >
                    {{ bar.label }} · {{ bar.display ?? bar.value }}
                </div>
            </div>
        </div>

        <div class="mt-2 flex gap-[3px]">
            <span
                v-for="bar in bars"
                :key="`label-${bar.index}`"
                class="flex-1 truncate text-center text-[0.65rem]"
                style="color: var(--text-muted)"
            >
                {{ bar.showLabel ? bar.label : '' }}
            </span>
        </div>

        <p v-if="isEmpty" class="mt-2 text-center text-xs" style="color: var(--text-muted)">
            Nothing recorded in this period yet.
        </p>
    </div>
</template>
