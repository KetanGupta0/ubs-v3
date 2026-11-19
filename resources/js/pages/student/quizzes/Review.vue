<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, XCircle, Hourglass } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    attempt: { type: Object, required: true },
    answers: { type: Array, default: () => [] },
    answersWaitForClose: { type: Boolean, default: false },
    closesAt: { type: String, default: null },
    integrity: { type: Object, default: null },
});

/* What a trainer decided about this attempt, and the one reply (§9.1). */
const replyForm = useForm({ reply: '' });
const decided = {
    discuss: 'Your trainer would like to talk to you about this attempt',
    voided: 'This attempt does not count',
    cleared: 'This attempt stands',
    warned: 'A warning about this attempt',
};

function sendReply() {
    replyForm.post(`/student/quizzes/${props.quiz.id}/attempts/${props.attempt.id}/integrity-reply`, { preserveScroll: true });
}

/** An answer and a key are both the option's own words; true or false in any case. */
function words(answer, values) {
    if (answer.type === 'truefalse') return String(values[0]).toLowerCase() === 'true' ? 'True' : 'False';

    return values.filter((value) => value !== null && value !== '').join(', ');
}

/** What the student picked. */
function chose(answer) {
    if (answer.response === null || answer.response === undefined) return 'Not answered';

    return words(answer, Array.isArray(answer.response) ? answer.response : [answer.response]) || 'Not answered';
}

function key(answer) {
    if (!answer.correct) return null;

    return words(answer, Array.isArray(answer.correct) ? answer.correct : [answer.correct]);
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

            <!-- What a trainer decided, and the one reply before it is final (§9.1). -->
            <UiCard v-if="integrity">
                <p class="text-sm font-semibold">{{ decided[integrity.outcome] ?? 'About this attempt' }}</p>
                <p v-if="integrity.reason" class="mt-1 text-sm" style="color: var(--text-base)">{{ integrity.reason }}</p>
                <p v-if="integrity.outcome === 'voided'" class="mt-2 text-sm" style="color: var(--text-muted)">
                    It stays on your record and counts for nothing.{{ integrity.resit ? ' You can sit the quiz again.' : '' }}
                </p>

                <div v-if="integrity.reply" class="mt-3 rounded-[var(--radius-field)] px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                    <p class="text-xs font-medium" style="color: var(--text-muted)">What you replied</p>
                    <p class="mt-1 whitespace-pre-line">{{ integrity.reply }}</p>
                </div>

                <form v-if="integrity.awaitsReply" class="mt-4 space-y-2" @submit.prevent="sendReply">
                    <label class="text-sm font-medium" for="integrity-reply">If something was different from how it looks, tell your trainer. You can reply once.</label>
                    <UiTextarea id="integrity-reply" v-model="replyForm.reply" :rows="3" maxlength="2000" />
                    <p v-if="replyForm.errors.reply" class="text-xs" style="color: var(--text-danger)">{{ replyForm.errors.reply }}</p>
                    <UiButton type="submit" size="sm" :loading="replyForm.processing" :disabled="!replyForm.reply.trim()">Send your reply</UiButton>
                </form>
                <p v-else class="mt-3 text-xs" style="color: var(--text-muted)">{{ integrity.final ? 'This is final.' : 'Your trainer reads your reply before this is final.' }}</p>
            </UiCard>

            <!-- Ended for them (§9.1a): who, and why, in plain words. -->
            <UiCard v-if="attempt.endedReason">
                <p class="text-sm font-semibold">{{ attempt.endedByProctor ? 'The proctor ended this attempt' : 'This attempt was submitted for you' }}</p>
                <p class="mt-1 text-sm" style="color: var(--text-base)">{{ attempt.endedReason }}</p>
                <p class="mt-2 text-sm" style="color: var(--text-muted)">
                    What you had answered was marked. Your trainer looks at what happened and decides whether you can sit it again.
                </p>
            </UiCard>

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

            <!-- Everybody sits the same questions, so the key waits until nobody can still use it. -->
            <UiCard v-else-if="answersWaitForClose">
                <p class="text-sm" style="color: var(--text-muted)">
                    The answers are shown once the quiz has closed for everybody<template v-if="closesAt">, after {{ closesAt }}</template>. Your score is above.
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
                            :style="{ color: answer.isCorrect ? 'var(--text-success)' : 'var(--text-danger)' }"
                        />
                        <span class="text-sm tnum" style="color: var(--text-muted)">
                            {{ answer.marks }}/{{ answer.outOf }}
                        </span>
                    </span>
                </div>

                <dl class="mt-3 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">You answered</dt>
                        <dd :style="!answer.isCorrect && !answer.awaitingMarking ? { color: 'var(--text-danger)' } : {}">
                            {{ chose(answer) }}
                        </dd>
                    </div>

                    <div v-if="!answer.isCorrect && key(answer)">
                        <dt class="text-xs" style="color: var(--text-muted)">The answer</dt>
                        <dd style="color: var(--text-success)">{{ key(answer) }}</dd>
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
