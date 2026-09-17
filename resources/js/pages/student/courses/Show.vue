<script setup>
/**
 * The course, as a student sees it.
 *
 * Locked lessons are listed with the reason they are locked. Hiding them takes
 * away the thing that makes somebody finish the lesson they are on.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    Lock, CheckCircle2, Circle, PlayCircle, Clock, Calendar, CreditCard,
    ClipboardList, FileText, FolderOpen, ArrowRight, AlertTriangle,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    enrolment: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    quizzes: { type: Array, default: () => [] },
    assignments: { type: Array, default: () => [] },
});

const lockIcons = {
    payment: CreditCard,
    schedule: Calendar,
    prerequisite: Circle,
    quiz: ClipboardList,
};
</script>

<template>
    <Head :title="course.title" />

    <AppLayout
        :title="course.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: 'Courses', href: '/student/courses' },
            { label: course.title },
        ]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="course.title" :description="course.tagline">
                <template #actions>
                    <UiButton :href="`/student/courses/${course.id}/materials`" variant="secondary" size="sm">
                        <template #leading><FolderOpen class="h-3.5 w-3.5" /></template>
                        Materials
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-2">
                    <UiBadge v-if="course.type === 'internship'" tone="accent" size="sm">internship</UiBadge>
                    <UiBadge v-if="enrolment.batch" size="sm">{{ enrolment.batch }}</UiBadge>
                    <UiBadge v-if="enrolment.status === 'completed'" tone="success" size="sm" dot>completed</UiBadge>
                </div>

                <div class="mt-4">
                    <UiProgress :value="enrolment.progress" label="Your progress" size="lg" />
                </div>

                <p
                    v-if="!enrolment.hasPaid"
                    class="mt-4 flex flex-wrap items-center gap-2 rounded-[var(--radius-field)] px-3 py-2.5 text-sm"
                    style="background: var(--surface-sunken)"
                >
                    <AlertTriangle class="h-4 w-4 shrink-0" style="color: var(--color-warn-500)" />
                    <span class="min-w-0 flex-1" style="color: var(--text-muted)">
                        {{ enrolment.feeDue }} outstanding. You can see everything here; the lessons marked with a
                        lock open once it is paid.
                    </span>
                    <UiButton v-if="enrolment.paymentId" :href="`/student/payments/${enrolment.paymentId}`" size="sm">
                        Pay now
                    </UiButton>
                </p>
            </UiCard>

            <!-- ------------------------------------------------- modules -->
            <UiCard v-for="module in modules" :key="module.id">
                <template #header>
                    <div>
                        <h2 class="text-base font-semibold">{{ module.title }}</h2>
                        <p v-if="module.summary" class="mt-0.5 text-sm" style="color: var(--text-muted)">
                            {{ module.summary }}
                        </p>
                    </div>
                </template>

                <UiEmptyState v-if="!module.lessons.length" title="Nothing in this module yet" />

                <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="lesson in module.lessons" :key="lesson.id">
                        <component
                            :is="lesson.lock.open ? Link : 'div'"
                            :href="lesson.lock.open ? `/student/courses/${course.id}/lessons/${lesson.id}` : undefined"
                            class="flex items-start gap-3 py-3 first:pt-0 last:pb-0"
                            :class="lesson.lock.open && 'transition hover:opacity-80'"
                        >
                            <component
                                :is="lesson.completed ? CheckCircle2 : lesson.lock.open ? PlayCircle : Lock"
                                class="mt-0.5 h-[18px] w-[18px] shrink-0"
                                :style="{
                                    color: lesson.completed
                                        ? 'var(--color-signal-500)'
                                        : lesson.lock.open
                                            ? 'var(--color-brand-500)'
                                            : 'var(--text-muted)',
                                }"
                            />

                            <span class="min-w-0 flex-1">
                                <span
                                    class="flex flex-wrap items-center gap-2 text-sm font-medium"
                                    :style="!lesson.lock.open ? { color: 'var(--text-muted)' } : {}"
                                >
                                    {{ lesson.title }}
                                    <UiBadge v-if="lesson.isPreview" size="sm">preview</UiBadge>
                                </span>

                                <span v-if="lesson.summary && lesson.lock.open" class="mt-0.5 block text-xs"
                                      style="color: var(--text-muted)">
                                    {{ lesson.summary }}
                                </span>

                                <span
                                    v-if="!lesson.lock.open"
                                    class="mt-0.5 flex items-center gap-1.5 text-xs"
                                    style="color: var(--text-muted)"
                                >
                                    <component :is="lockIcons[lesson.lock.kind] ?? Lock" class="h-3 w-3" />
                                    {{ lesson.lock.reason }}
                                </span>
                            </span>

                            <span v-if="lesson.duration" class="flex shrink-0 items-center gap-1 text-xs"
                                  style="color: var(--text-muted)">
                                <Clock class="h-3 w-3" />{{ lesson.duration }}
                            </span>
                        </component>
                    </li>
                </ul>
            </UiCard>

            <!-- --------------------------------------- quizzes and work -->
            <div class="grid gap-5 sm:grid-cols-2">
                <UiCard v-if="quizzes.length">
                    <template #header>
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <ClipboardList class="h-4 w-4" style="color: var(--text-muted)" />
                            Quizzes
                        </h2>
                    </template>

                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="quiz in quizzes" :key="quiz.id" class="py-3 first:pt-0 last:pb-0">
                            <Link :href="`/student/quizzes/${quiz.id}`" class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium">{{ quiz.title }}</span>
                                    <span class="block text-xs" style="color: var(--text-muted)">
                                        {{ quiz.questions }} questions
                                        <span v-if="quiz.closedReason"> · {{ quiz.closedReason }}</span>
                                        <span v-else-if="quiz.attemptsLeft"> · {{ quiz.attemptsLeft }} attempts left</span>
                                        <span v-else> · no attempts left</span>
                                    </span>
                                </span>
                                <UiBadge v-if="quiz.best !== null" :tone="quiz.best >= 50 ? 'success' : 'warning'" size="sm">
                                    {{ Math.round(quiz.best) }}%
                                </UiBadge>
                                <ArrowRight v-else class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                            </Link>
                        </li>
                    </ul>
                </UiCard>

                <UiCard v-if="assignments.length">
                    <template #header>
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <FileText class="h-4 w-4" style="color: var(--text-muted)" />
                            Assignments
                        </h2>
                    </template>

                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="assignment in assignments" :key="assignment.id" class="py-3 first:pt-0 last:pb-0">
                            <Link :href="`/student/assignments/${assignment.id}`" class="flex items-start justify-between gap-3">
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        {{ assignment.title }}
                                        <UiBadge v-if="assignment.isProject" tone="accent" size="sm">project</UiBadge>
                                    </span>
                                    <span class="block text-xs" style="color: var(--text-muted)">
                                        <span v-if="assignment.dueAt">Due {{ assignment.dueAt }}</span>
                                        <span v-else>No deadline</span>
                                    </span>
                                </span>
                                <UiBadge
                                    v-if="assignment.marks !== null"
                                    tone="success"
                                    size="sm"
                                >
                                    {{ assignment.marks }}/{{ assignment.maxMarks }}
                                </UiBadge>
                                <UiBadge v-else-if="assignment.submitted" size="sm">submitted</UiBadge>
                                <UiBadge v-else-if="assignment.overdue" tone="warning" size="sm">overdue</UiBadge>
                            </Link>
                        </li>
                    </ul>
                </UiCard>
            </div>
        </div>
    </AppLayout>
</template>
