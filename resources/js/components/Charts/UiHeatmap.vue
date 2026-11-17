<script setup>
/**
 * A calendar heatmap: one square per day, a column per week, Monday at the
 * top. Darker means more.
 *
 * "More" is ordered, so the squares use the one hue ramp (`--chart-step-*`),
 * never series colours and never status colours: a busy day is not "good"
 * and a quiet one is not "bad". An empty day is the sunken surface, so a gap
 * reads as a gap. Put it in a UiChartFrame, which carries the table of
 * numbers for anybody who cannot read colour.
 */
import { computed } from 'vue';

import { rampColor } from '@/support/charts';

const props = defineProps({
    /** [{ date: 'YYYY-MM-DD', label, count, future? }], oldest first, whole weeks from a Monday. */
    days: { type: Array, default: () => [] },
    /** How one day's count is said, for the tooltip and screen readers. */
    unit: { type: Function, default: (count) => `${count} ${count === 1 ? 'thing' : 'things'}` },
});

const STEPS = 4;

const max = computed(() => Math.max(0, ...props.days.map((day) => day.count)));

/** Which step of the ramp a count falls on: 0 for nothing, then 1 to 4 by share of the busiest day. */
function step(count) {
    if (!count || !max.value) return 0;

    return Math.min(STEPS, Math.max(1, Math.ceil((count / max.value) * STEPS)));
}

function fill(day) {
    if (day.future) return 'transparent';
    const level = step(day.count);

    return level === 0 ? 'var(--surface-sunken)' : rampColor(level - 1, STEPS);
}

/** An empty or future day keeps a faint edge, so the grid reads in both themes. */
function edge(day) {
    if (day.future) return '0 0 0 1px var(--border-subtle) inset';

    return step(day.count) === 0 ? '0 0 0 1px var(--border-subtle) inset' : 'none';
}

const weeks = computed(() => {
    const columns = [];
    for (let index = 0; index < props.days.length; index += 7) {
        columns.push(props.days.slice(index, index + 7));
    }

    return columns;
});

/**
 * A month name over the first week that starts in it. A name needs three
 * columns of room, so one that would crowd the next is left out (usually the
 * part month at the very start).
 */
const months = computed(() => {
    const starts = weeks.value.map((week, index) => {
        const first = new Date(`${week[0].date}T00:00:00`);
        const previous = index > 0 ? new Date(`${weeks.value[index - 1][0].date}T00:00:00`) : null;

        return !previous || previous.getMonth() !== first.getMonth()
            ? first.toLocaleString('en-IN', { month: 'short' })
            : '';
    });

    return starts.map((name, index) => {
        if (!name) return '';
        const next = starts.slice(index + 1, index + 3).some(Boolean);

        return next ? '' : name;
    });
});

const dayNames = ['Mon', '', 'Wed', '', 'Fri', '', ''];
const legend = Array.from({ length: STEPS + 1 }, (_, level) => (level === 0 ? 'var(--surface-sunken)' : rampColor(level - 1, STEPS)));
</script>

<template>
    <div class="overflow-x-auto">
        <div class="inline-flex flex-col gap-1">
            <div class="flex gap-[3px] pl-8 text-[10px]" style="color: var(--text-muted)" aria-hidden="true">
                <span v-for="(month, index) in months" :key="index" class="w-3.5 overflow-visible whitespace-nowrap">{{ month }}</span>
            </div>

            <div class="flex gap-[3px]">
                <div class="flex w-7 flex-col gap-[3px] text-[10px]" style="color: var(--text-muted)" aria-hidden="true">
                    <span v-for="(name, index) in dayNames" :key="index" class="h-3.5 leading-[14px]">{{ name }}</span>
                </div>

                <div v-for="(week, index) in weeks" :key="index" class="flex flex-col gap-[3px]" role="list">
                    <span
                        v-for="day in week"
                        :key="day.date"
                        role="listitem"
                        class="h-3.5 w-3.5 rounded-[3px]"
                        :style="{ background: fill(day), boxShadow: edge(day), opacity: day.future ? 0.5 : 1 }"
                        :title="day.future ? day.label : `${day.label}: ${unit(day.count)}`"
                        :aria-label="day.future ? `${day.label}, still to come` : `${day.label}: ${unit(day.count)}`"
                    />
                </div>
            </div>

            <div class="mt-1 flex items-center justify-end gap-1 text-[10px]" style="color: var(--text-muted)" aria-hidden="true">
                Less
                <span v-for="(colour, index) in legend" :key="index" class="h-3.5 w-3.5 rounded-[3px]" :style="{ background: colour, boxShadow: index === 0 ? '0 0 0 1px var(--border-subtle) inset' : 'none' }" />
                More
            </div>
        </div>
    </div>
</template>
