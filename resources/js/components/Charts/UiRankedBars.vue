<script setup>
/**
 * Ordered bands, horizontally.
 *
 * Age buckets and funnel stages have long names and a meaningful order, and
 * both of those point the same way: lay the bars along the reading direction,
 * put the label where the eye starts, and the value at the tip where the bar
 * stops. One hue getting darker carries the order, so the reader does not have
 * to read the labels to recover it.
 */
import { computed } from 'vue';

import { rampColor } from '@/support/charts';

const props = defineProps({
    /** [{ label, value, display? }] in the order they should be read. */
    rows: { type: Array, default: () => [] },
    /** Shown under each bar, for a funnel: "62% of the stage before". */
    notes: { type: Array, default: () => [] },
});

const highest = computed(() => Math.max(1, ...props.rows.map((row) => Number(row.value) || 0)));
</script>

<template>
    <ul class="space-y-2.5">
        <li v-for="(row, index) in rows" :key="row.label">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-xs" style="color: var(--text-base)">{{ row.label }}</span>
                <span class="text-xs font-semibold tnum" style="color: var(--text-strong)">
                    {{ row.display ?? row.value }}
                </span>
            </div>

            <div class="mt-1 h-3 w-full overflow-hidden rounded-[4px]" style="background: var(--surface-sunken)">
                <div
                    class="h-full rounded-r-[4px] transition-[width] duration-[var(--duration-slow)]"
                    :style="{
                        width: `${Math.max(((Number(row.value) || 0) / highest) * 100, Number(row.value) > 0 ? 1.5 : 0)}%`,
                        background: rampColor(index, rows.length),
                    }"
                />
            </div>

            <p v-if="notes[index]" class="mt-0.5 text-[0.68rem]" style="color: var(--text-muted)">
                {{ notes[index] }}
            </p>
        </li>
    </ul>
</template>
