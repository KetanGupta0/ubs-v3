<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Clock, Play, CheckCircle2, XCircle, Hourglass } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    openAttemptId: { type: Number, default: null },
    attempts: { type: Array, default: () => [] },
});

function start() {
    router.post(`/student/quizzes/${props.quiz.id}/start`);
}
</script>

<template>
    <Head :title="quiz.title" />

    <AppLayout
        :title="quiz.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: quiz.course, href: `/student/courses/${quiz.courseId}` },
            { label: 'Quiz' },
        ]"
    >
        <div class="mx-auto max-w-2xl space-y-5">
            <PageHeader :title="quiz.title">
                <template #actions>
                    <UiButton :href="`/student/courses/${quiz.courseId}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back to the course
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <dl class="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Questions</dt>
                        <dd class="font-medium tnum">{{ quiz.questionCount }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Marks</dt>
                        <dd class="font-medium tnum">{{ quiz.totalMarks }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Time limit</dt>
                        <dd class="font-medium">{{ quiz.timeLimit ? `${quiz.timeLimit} min` : 'None' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">To pass</dt>
                        <dd class="font-medium tnum">{{ quiz.passPercent }}%</dd>
                    </div>
                </dl>

                <p v-if="quiz.instructions" class="mt-5 whitespace-pre-line rounded-[var(--radius-field)] px-4 py-3 text-sm"
                   style="background: var(--surface-sunken)">
                    {{ quiz.instructions }}
                </p>

                <p class="mt-4 text-sm" style="color: var(--text-muted)">
                    You have used {{ quiz.attemptsUsed }} of {{ quiz.attemptsAllowed }}
                    {{ quiz.attemptsAllowed === 1 ? 'attempt' : 'attempts' }}.
                    <span v-if="quiz.timeLimit">
                        The clock starts when you begin and runs on our server, so closing the tab does not stop it.
                    </span>
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <UiButton v-if="openAttemptId" :href="`/student/quizzes/${quiz.id}/attempts/${openAttemptId}`">
                        <template #leading><Hourglass class="h-4 w-4" /></template>
                        Carry on with your attempt
                    </UiButton>
                    <UiButton v-else-if="quiz.canAttempt" @click="start">
                        <template #leading><Play class="h-4 w-4" /></template>
                        Start the quiz
                    </UiButton>
                    <p v-else class="text-sm" style="color: var(--text-muted)">
                        {{ quiz.closedReason || 'No attempts left on this one.' }}
                    </p>
                </div>
            </UiCard>

            <UiCard v-if="attempts.length">
                <template #header><h2 class="text-base font-semibold">Your attempts</h2></template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="attempt in attempts" :key="attempt.id"
                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <component
                            :is="attempt.needsReview ? Hourglass : attempt.passed ? CheckCircle2 : XCircle"
                            class="h-4 w-4 shrink-0"
                            :style="{
                                color: attempt.needsReview
                                    ? 'var(--text-muted)'
                                    : attempt.passed ? 'var(--color-signal-500)' : 'var(--color-danger-500)',
                            }"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">Attempt {{ attempt.number }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">{{ attempt.at }}</span>
                        </span>

                        <span class="tnum text-sm">{{ attempt.score }} / {{ attempt.totalMarks }}</span>

                        <UiBadge
                            v-if="attempt.needsReview"
                            size="sm"
                        >
                            being marked
                        </UiBadge>
                        <UiBadge v-else :tone="attempt.passed ? 'success' : 'danger'" size="sm">
                            {{ Math.round(attempt.percent) }}%
                        </UiBadge>

                        <UiButton
                            v-if="quiz.showAnswers"
                            :href="`/student/quizzes/${quiz.id}/attempts/${attempt.id}/review`"
                            variant="ghost"
                            size="xs"
                        >
                            Review
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
