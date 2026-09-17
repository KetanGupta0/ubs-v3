<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { GraduationCap, ClipboardList, FileText, CalendarCheck, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    courses: { type: Array, default: () => [] },
});

const toneFor = (result) => {
    if (result.overall === null) return 'brand';
    if (result.passing) return 'success';
    return result.overall >= result.passMark - 10 ? 'warning' : 'danger';
};

const mark = (value) => (value === null || value === undefined ? '—' : `${value}%`);
</script>

<template>
    <Head title="Results" />

    <AppLayout title="Results" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Results' }]">
        <div class="mx-auto max-w-4xl space-y-6">
            <PageHeader
                title="Your results"
                description="One figure per course, and everything that went into it. The weights are shown so the number can be argued with."
            />

            <UiEmptyState
                v-if="!courses.length"
                :icon="GraduationCap"
                title="Nothing to report yet"
                description="Results appear once you are enrolled and your first quiz or assignment has been marked."
            />

            <section v-for="entry in courses" :key="entry.courseId" class="space-y-3">
                <UiCard>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold">{{ entry.course }}</h2>
                            <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                {{ [entry.batch, entry.type === 'internship' ? 'Internship' : null].filter(Boolean).join(' · ') || 'Self paced' }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-3xl font-semibold tnum leading-none">{{ mark(entry.result.overall) }}</p>
                            <p class="mt-1 text-xs" style="color: var(--text-muted)">
                                pass mark {{ entry.result.passMark }}%
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <!-- The grade is already a word, so it reads on its own. Below the
                             pass mark it says nothing the badge beside it does not. -->
                        <UiBadge v-if="entry.result.grade && entry.result.passing" :tone="toneFor(entry.result)">
                            {{ entry.result.grade }}
                        </UiBadge>
                        <UiBadge v-if="entry.result.complete" tone="success">course complete</UiBadge>
                        <UiBadge v-else-if="entry.result.overall !== null && entry.result.passing" tone="success">
                            on track to pass
                        </UiBadge>
                        <UiBadge v-else-if="entry.result.overall !== null" tone="warning">
                            below the pass mark
                        </UiBadge>
                    </div>

                    <p
                        v-if="entry.result.shortOnAttendance"
                        class="mt-4 flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-xs"
                        style="background: var(--surface-sunken); color: var(--text-muted)"
                    >
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                        <span>
                            This course asks for {{ entry.result.attendanceFloor }}% attendance and you are on
                            {{ mark(entry.result.attendance.percent) }}. Marks alone will not carry a pass here,
                            so talk to your trainer before the batch ends rather than after.
                        </span>
                    </p>

                    <div class="mt-5">
                        <UiProgress
                            :value="entry.result.progress ?? 0"
                            label="Course completed"
                            :tone="toneFor(entry.result)"
                        />
                    </div>
                </UiCard>

                <!-- ------------------------------------------- the parts -->
                <div class="grid gap-3 sm:grid-cols-3">
                    <UiCard padding="p-4">
                        <p class="flex items-center gap-1.5 text-xs font-medium" style="color: var(--text-muted)">
                            <ClipboardList class="h-3.5 w-3.5" />
                            Quizzes
                            <span class="ml-auto">{{ entry.result.weights.quizzes }}%</span>
                        </p>
                        <p class="mt-2 text-xl font-semibold tnum">{{ mark(entry.result.quizzes.percent) }}</p>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                            {{ entry.result.quizzes.taken }} of {{ entry.result.quizzes.total }} taken
                        </p>
                    </UiCard>

                    <UiCard padding="p-4">
                        <p class="flex items-center gap-1.5 text-xs font-medium" style="color: var(--text-muted)">
                            <FileText class="h-3.5 w-3.5" />
                            Assignments
                            <span class="ml-auto">{{ entry.result.weights.assignments }}%</span>
                        </p>
                        <p class="mt-2 text-xl font-semibold tnum">{{ mark(entry.result.assignments.percent) }}</p>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                            {{ entry.result.assignments.submitted }} of {{ entry.result.assignments.total }} handed in
                        </p>
                    </UiCard>

                    <UiCard padding="p-4">
                        <p class="flex items-center gap-1.5 text-xs font-medium" style="color: var(--text-muted)">
                            <CalendarCheck class="h-3.5 w-3.5" />
                            Attendance
                            <span class="ml-auto">{{ entry.result.weights.attendance }}%</span>
                        </p>
                        <p class="mt-2 text-xl font-semibold tnum">{{ mark(entry.result.attendance.percent) }}</p>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                            {{ entry.result.attendance.attended }} of {{ entry.result.attendance.held }} classes
                        </p>
                    </UiCard>
                </div>

                <!-- ------------------------------------------ the detail -->
                <UiCard v-if="entry.result.quizzes.rows.length || entry.result.assignments.rows.length" padding="p-0">
                    <div v-if="entry.result.quizzes.rows.length" class="p-4">
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                            Quizzes
                        </h3>
                        <ul class="divide-y" style="border-color: var(--border-subtle)">
                            <li
                                v-for="quiz in entry.result.quizzes.rows"
                                :key="quiz.id"
                                class="flex items-center gap-3 py-2 text-sm"
                            >
                                <Link :href="`/student/quizzes/${quiz.id}`" class="min-w-0 flex-1 truncate hover:underline">
                                    {{ quiz.title }}
                                </Link>
                                <UiBadge v-if="quiz.awaitingReview" size="sm">being reviewed</UiBadge>
                                <UiBadge v-else-if="!quiz.taken" size="sm">not taken</UiBadge>
                                <UiBadge v-else :tone="quiz.passed ? 'success' : 'warning'" size="sm">
                                    {{ quiz.percent }}%
                                </UiBadge>
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="entry.result.assignments.rows.length"
                        class="p-4"
                        :class="entry.result.quizzes.rows.length ? 'border-t' : ''"
                        style="border-color: var(--border-subtle)"
                    >
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                            Assignments
                        </h3>
                        <ul class="divide-y" style="border-color: var(--border-subtle)">
                            <li
                                v-for="task in entry.result.assignments.rows"
                                :key="task.id"
                                class="flex items-center gap-3 py-2 text-sm"
                            >
                                <Link :href="`/student/assignments/${task.id}`" class="min-w-0 flex-1 truncate hover:underline">
                                    {{ task.title }}
                                </Link>
                                <UiBadge v-if="task.late" tone="warning" size="sm">late</UiBadge>
                                <UiBadge v-if="task.marks !== null" tone="success" size="sm">
                                    {{ task.marks }} / {{ task.maxMarks }}
                                </UiBadge>
                                <UiBadge v-else-if="task.awaitingMarking" size="sm">waiting to be marked</UiBadge>
                                <UiBadge v-else-if="task.overdue" tone="warning" size="sm">overdue</UiBadge>
                                <UiBadge v-else size="sm">not handed in</UiBadge>
                            </li>
                        </ul>
                    </div>
                </UiCard>

                <!-- Classes missed, listed rather than summarised, because a
                     percentage does not tell you which evening to catch up on. -->
                <UiCard v-if="entry.result.attendance.missed?.length" padding="p-4">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        Classes you missed
                    </h3>
                    <ul class="space-y-1 text-sm">
                        <li
                            v-for="session in entry.result.attendance.missed"
                            :key="session.id"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="min-w-0 truncate">{{ session.title }}</span>
                            <span class="shrink-0 text-xs" style="color: var(--text-muted)">{{ session.on }}</span>
                        </li>
                    </ul>
                </UiCard>
            </section>

            <p v-if="courses.length" class="px-1 text-xs" style="color: var(--text-muted)">
                A component with nothing in it yet is left out and its weight shared between the others, so a course
                with no quizzes does not hold everybody at seventy percent.
            </p>
        </div>
    </AppLayout>
</template>
