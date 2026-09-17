<script setup>
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, XCircle, Hourglass } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    attempt: { type: Object, required: true },
    answers: { type: Array, default: () => [] },
});

/** What the student picked, in words rather than indexes. */
function chose(answer) {
    if (answer.response === null || answer.response === undefined) return 'Not answered';

    const values = Array.isArray(answer.response) ? answer.response : [answer.response];

    if (answer.type === 'short') return values[0] || 'Not answered';
    if (answer.type === 'truefalse') return values[0] === 'true' ? 'True' : 'False';

    return values.map((index) => answer.options[Number(index)] ?? index).join(', ') || 'Not answered';
}

function key(answer) {
    if (!answer.correct) return null;

    const values = Array.isArray(answer.correct) ? answer.correct : [answer.correct];

    if (answer.type === 'truefalse') return String(values[0]) === 'true' ? 'True' : 'False';

    return values.map((index) => answer.options[Number(index)] ?? index).join(', ');
}
</script>

<template>
    <Head :title="`${quiz.title} — your attempt`" />

    <AppLayout
        :title="quiz.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: 'Quiz', href: `/student/quizzes/${quiz.id}` },
            { label: `Attempt ${attempt.number}` },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold sm:text-2xl">{{ quiz.title }}</h1>
                    <p class="mt-1 text-sm" style="color: var(--text-muted)">
                        Attempt {{ attempt.number }} · {{ attempt.at }}
                    </p>
                </div>

                <UiButton :href="`/student/quizzes/${quiz.id}`" variant="ghost" size="sm">
                    <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                    Back
                </UiButton>
            </div>

            <!-- ------------------------------------------------- the score -->
            <UiCard>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="text-xs" style="color: var(--text-muted)">Your score</p>
                        <p class="text-3xl font-semibold tnum">
                            {{ attempt.score }} <span class="text-lg" style="color: var(--text-muted)">/ {{ attempt.totalMarks }}</span>
                        </p>
                    </div>

                    <UiBadge
                        v-if="attempt.needsReview"
                        size="md"
                    >
                        <Hourglass class="mr-1.5 h-3.5 w-3.5" />
                        A written answer is still being marked
                    </UiBadge>
                    <UiBadge v-else :tone="attempt.passed ? 'success' : 'danger'" size="md" dot>
                        {{ attempt.passed ? 'Passed' : 'Not passed' }}
                    </UiBadge>
                </div>

                <div class="mt-4">
                    <UiProgress
                        :value="attempt.percent"
                        :label="`Pass mark is ${quiz.passPercent}%`"
                        :tone="attempt.passed ? 'success' : 'warning'"
                    />
                </div>
            </UiCard>

            <!-- ------------------------------------------------- answers -->
            <UiCard v-if="!quiz.showAnswers">
                <p class="text-sm" style="color: var(--text-muted)">
                    This quiz does not show the answers afterwards. Your score is above.
                </p>
            </UiCard>

            <UiCard v-for="(answer, index) in answers" :key="answer.id">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-sm font-medium">
                        <span style="color: var(--text-muted)">{{ index + 1 }}.</span>
                        {{ answer.question }}
                    </p>

                    <span class="flex shrink-0 items-center gap-2">
                        <component
                            v-if="!answer.awaitingMarking"
                            :is="answer.isCorrect ? CheckCircle2 : XCircle"
                            class="h-4 w-4"
                            :style="{ color: answer.isCorrect ? 'var(--color-signal-500)' : 'var(--color-danger-500)' }"
                        />
                        <span class="text-sm tnum" style="color: var(--text-muted)">
                            {{ answer.marks }}/{{ answer.outOf }}
                        </span>
                    </span>
                </div>

                <dl class="mt-3 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">You answered</dt>
                        <dd :style="!answer.isCorrect && !answer.awaitingMarking ? { color: 'var(--color-danger-500)' } : {}">
                            {{ chose(answer) }}
                        </dd>
                    </div>

                    <div v-if="!answer.isCorrect && key(answer)">
                        <dt class="text-xs" style="color: var(--text-muted)">The answer</dt>
                        <dd style="color: var(--color-signal-600)">{{ key(answer) }}</dd>
                    </div>
                </dl>

                <p v-if="answer.awaitingMarking" class="mt-3 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                   style="background: var(--surface-sunken); color: var(--text-muted)">
                    Waiting to be marked by a person.
                </p>

                <p v-if="answer.feedback" class="mt-3 rounded-[var(--radius-field)] px-3 py-2 text-sm"
                   style="background: var(--surface-sunken)">
                    <span class="text-xs font-medium" style="color: var(--text-muted)">Feedback:</span>
                    {{ answer.feedback }}
                </p>

                <p v-if="answer.explanation" class="mt-3 text-xs" style="color: var(--text-muted)">
                    {{ answer.explanation }}
                </p>
            </UiCard>
        </div>
    </AppLayout>
</template>
