<script setup>
/**
 * How a student is doing on one course: attendance, work on time, quiz
 * results over time, lessons finished, and their place on the batch board.
 * The same card on the student's own page and on their record for staff.
 */
import { computed } from 'vue';
import { AlertTriangle, ArrowDownRight, ArrowRight, ArrowUpRight, Trophy } from 'lucide-vue-next';

import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiChartFrame from '@/components/Charts/UiChartFrame.vue';
import UiLineChart from '@/components/Charts/UiLineChart.vue';
import { withColors } from '@/support/charts';

const props = defineProps({
    performance: { type: Object, required: true },
    /** Words for the student ("you") or for staff ("they"). */
    audience: { type: String, default: 'student' },
});

const p = computed(() => props.performance);

const trend = computed(() => ({
    up: { icon: ArrowUpRight, text: 'going up' },
    down: { icon: ArrowDownRight, text: 'going down' },
    steady: { icon: ArrowRight, text: 'holding steady' },
}[p.value.quizzes.trend] ?? null));

const series = computed(() => withColors([{ label: 'Quiz score', values: p.value.quizzes.attempts.map((attempt) => attempt.percent) }]));

const percent = (value) => (value === null || value === undefined ? '—' : `${value}%`);
</script>

<template>
    <UiCard>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold">{{ p.course }}</h2>
                    <p v-if="p.batch" class="text-sm" style="color: var(--text-muted)">{{ p.batch }}</p>
                </div>
                <UiBadge v-if="p.rank" tone="brand">
                    <Trophy class="mr-1 h-3.5 w-3.5" />
                    {{ p.rank.rank }} of {{ p.rank.outOf }} on the batch · {{ p.rank.points }} points
                </UiBadge>
            </div>
        </template>

        <div class="space-y-5">
            <div v-if="p.signals.length" class="rounded-xl border px-4 py-3" style="border-color: var(--color-warn-500)">
                <p class="flex items-center gap-2 text-sm font-medium">
                    <AlertTriangle class="h-4 w-4" style="color: var(--color-warn-600)" />
                    {{ audience === 'student' ? 'Worth a look' : 'Signals' }}
                </p>
                <ul class="mt-1 list-disc pl-6 text-sm" style="color: var(--text-muted)">
                    <li v-for="signal in p.signals" :key="signal.key">{{ signal.label }}</li>
                </ul>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Attendance"
                    :value="percent(p.attendance.percent)"
                    :hint="p.attendance.held ? `${p.attendance.attended} of ${p.attendance.held} classes` : 'No classes held yet'"
                />
                <StatTile
                    label="Work on time"
                    :value="percent(p.assignments.percent)"
                    :hint="p.assignments.due ? `${p.assignments.onTime} on time, ${p.assignments.late} late, ${p.assignments.missing} not handed in` : 'Nothing due yet'"
                />
                <StatTile
                    label="Quiz average"
                    :value="percent(p.quizzes.average)"
                    :hint="trend ? `Results ${trend.text}` : (p.quizzes.attempts.length ? 'One quiz so far' : 'No quizzes sat yet')"
                />
                <StatTile
                    label="Lessons"
                    :value="percent(p.lessons.percent)"
                    :hint="`${p.lessons.done} of ${p.lessons.total} finished`"
                />
            </div>

            <UiChartFrame
                v-if="p.quizzes.attempts.length >= 2"
                title="Quiz results over time"
                :series="series"
                key-shape="line"
                :columns="['Quiz', 'Date', 'Score']"
                :rows="p.quizzes.attempts.map((attempt) => [attempt.quiz, attempt.date, `${attempt.percent}%`])"
            >
                <UiLineChart
                    :categories="p.quizzes.attempts.map((attempt) => attempt.date)"
                    :series="series"
                    :height="180"
                    :tick="(value) => `${Math.round(value)}%`"
                />
            </UiChartFrame>

            <p class="text-xs" style="color: var(--text-muted)">
                {{ p.lastActive ? `Last active ${p.lastActive}.` : 'Nothing recorded yet.' }}
                Counted from what was recorded: classes marked, work handed in, quizzes sat and lessons finished.
            </p>
        </div>
    </UiCard>
</template>
