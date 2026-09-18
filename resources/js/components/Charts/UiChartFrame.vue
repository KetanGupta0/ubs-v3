<script setup>
/**
 * The frame every chart sits in.
 *
 * Title, legend, and the switch to "show the numbers". The last one is not a
 * nicety: a chart is a picture of data, and anybody who cannot see it, cannot
 * hover it, or simply wants to copy a figure out needs the figures themselves.
 * Putting that switch here means no chart in this codebase ships without one.
 *
 * A legend appears from two series upward. One series needs none — the title
 * already says what is plotted, and a box with a single swatch is furniture.
 */
import { ref, computed } from 'vue';
import { Table2, ChartNoAxesColumn } from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    /** [{ label, color }] — colours are chart tokens, assigned in order. */
    series: { type: Array, default: () => [] },
    /** Column headings for the table view. */
    columns: { type: Array, default: () => [] },
    /** [[cell, cell, …]] already formatted for reading. */
    rows: { type: Array, default: () => [] },
    /** 'line' or 'rect' — the legend key mirrors the mark. */
    keyShape: { type: String, default: 'rect' },
    empty: { type: Boolean, default: false },
    emptyText: { type: String, default: 'Nothing recorded in this period yet.' },
});

const showing = ref('chart');

const showLegend = computed(() => props.series.length > 1);
</script>

<template>
    <figure class="print-block">
        <figcaption v-if="title || $slots.actions" class="mb-3 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 v-if="title" class="text-sm font-semibold">{{ title }}</h3>
                <p v-if="subtitle" class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ subtitle }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <slot name="actions" />

                <button
                    v-if="rows.length"
                    type="button"
                    class="no-print inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-muted)"
                    :aria-pressed="showing === 'table'"
                    @click="showing = showing === 'chart' ? 'table' : 'chart'"
                >
                    <component :is="showing === 'chart' ? Table2 : ChartNoAxesColumn" class="h-3.5 w-3.5" />
                    {{ showing === 'chart' ? 'Show the numbers' : 'Show the chart' }}
                </button>
            </div>
        </figcaption>

        <!-- ------------------------------------------------------- legend -->
        <ul
            v-if="showLegend && showing === 'chart'"
            class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1.5"
        >
            <li
                v-for="entry in series"
                :key="entry.label"
                class="flex items-center gap-1.5 text-xs"
                style="color: var(--text-base)"
            >
                <span
                    v-if="keyShape === 'line'"
                    class="inline-block h-[2px] w-4 rounded-full"
                    :style="{ background: entry.color }"
                    aria-hidden="true"
                />
                <span
                    v-else
                    class="inline-block h-2.5 w-2.5 rounded-[3px]"
                    :style="{ background: entry.color }"
                    aria-hidden="true"
                />
                {{ entry.label }}
            </li>
        </ul>

        <p v-if="empty" class="py-10 text-center text-sm" style="color: var(--text-muted)">
            {{ emptyText }}
        </p>

        <div v-else-if="showing === 'chart'">
            <slot />
        </div>

        <!-- --------------------------------------------------- the numbers -->
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b" style="border-color: var(--border-subtle)">
                        <th
                            v-for="(column, index) in columns"
                            :key="column"
                            class="whitespace-nowrap px-2 py-1.5 text-xs font-medium"
                            :class="index === 0 ? 'text-left' : 'text-right'"
                            style="color: var(--text-muted)"
                            scope="col"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, rowIndex) in rows"
                        :key="rowIndex"
                        class="border-b last:border-0"
                        style="border-color: var(--border-subtle)"
                    >
                        <td
                            v-for="(cell, cellIndex) in row"
                            :key="cellIndex"
                            class="px-2 py-1.5"
                            :class="cellIndex === 0 ? 'text-left' : 'text-right tnum'"
                        >
                            {{ cell }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>
