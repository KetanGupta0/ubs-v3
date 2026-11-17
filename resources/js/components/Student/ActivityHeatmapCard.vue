<script setup>
/** A heatmap in its frame, with the days that had anything as the table behind it. */
import { computed } from 'vue';

import UiCard from '@/components/UI/UiCard.vue';
import UiChartFrame from '@/components/Charts/UiChartFrame.vue';
import UiHeatmap from '@/components/Charts/UiHeatmap.vue';

const props = defineProps({
    days: { type: Array, default: () => [] },
    title: { type: String, default: 'Activity' },
    subtitle: { type: String, default: null },
    /** Singular and plural for one day's count, e.g. ['thing', 'things'] or ['student', 'students']. */
    noun: { type: Array, default: () => ['thing', 'things'] },
});

const unit = (count) => `${count} ${count === 1 ? props.noun[0] : props.noun[1]}`;
const busy = computed(() => props.days.filter((day) => day.count > 0 && !day.future));
</script>

<template>
    <UiCard>
        <UiChartFrame
            :title="title"
            :subtitle="subtitle"
            :columns="['Day', noun[1].charAt(0).toUpperCase() + noun[1].slice(1)]"
            :rows="busy.slice().reverse().map((day) => [day.label, day.count])"
            :empty="!busy.length"
            empty-text="Nothing recorded in these weeks yet."
        >
            <UiHeatmap :days="days" :unit="unit" />
        </UiChartFrame>
    </UiCard>
</template>
