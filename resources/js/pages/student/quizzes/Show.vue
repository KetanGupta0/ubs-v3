<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Play, CheckCircle2, XCircle, Hourglass, Eye } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    openAttemptId: { type: Number, default: null },
    attempts: { type: Array, default: () => [] },
    proctoring: { type: Object, default: null },
});

const page = usePage();
const errors = computed(() => page.props.errors ?? {});

/* ------------------------------------------------- proctored (§9.1a) */

const agree = ref(false);
const starting = ref(false);

// A laptop or computer: a fine pointer and a wide screen. A phone asking for
// the desktop site can pass the server's check, but not this one.
const laptop = ref(true);
onMounted(() => {
    laptop.value = window.matchMedia('(pointer: fine)').matches && window.screen.width >= 1000;
});

const blocked = computed(() => {
    if (!props.proctoring) return null;
    if (!laptop.value) return 'This quiz is sat on a laptop or computer, not on a phone or tablet.';

    return props.proctoring.refusal;
});

async function start() {
    if (!props.proctoring) {
        router.post(`/student/quizzes/${props.quiz.id}/start`);

        return;
    }

    starting.value = true;

    // Full screen first: a browser allows it only from a click, and this is
    // the click. Safe Exam Browser is full screen by itself.
    if (!props.proctoring.requireSeb) {
        try {
            await document.documentElement.requestFullscreen({ navigationUI: 'hide' });
            await navigator.keyboard?.lock?.(['Escape']);
        } catch {
            // The quiz asks again, with a button, before showing a question.
        }
    }

    router.post(`/student/quizzes/${props.quiz.id}/start`, { agree: agree.value }, {
        onError: () => document.fullscreenElement && document.exitFullscreen().catch(() => {}),
        onFinish: () => (starting.value = false),
    });
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

                <!-- Proctored: when it can be sat, and in what (§9.1a). -->
                <div v-if="proctoring" class="mt-5 rounded-[var(--radius-field)] border px-4 py-3" style="border-color: var(--color-brand-500)">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <Eye class="h-4 w-4" style="color: var(--color-brand-500)" aria-hidden="true" />
                        Proctored quiz
                    </p>
                    <p class="mt-1 text-sm" style="color: var(--text-base)">
                        <template v-if="proctoring.slot">
                            {{ proctoring.slot.open ? 'Sitting now:' : 'Next sitting:' }} {{ proctoring.slot.window }}<template v-if="proctoring.slot.note"> · {{ proctoring.slot.note }}</template>
                        </template>
                        <template v-else>No sitting is scheduled for your batch yet.</template>
                    </p>
                    <p v-if="proctoring.requireSeb && !proctoring.inSeb" class="mt-3 text-sm" style="color: var(--text-base)">
                        It is taken in Safe Exam Browser, which locks the computer for the length of the quiz.
                        <a href="https://safeexambrowser.org/download_en.html" target="_blank" rel="noopener" class="font-medium underline">Install it</a>,
                        then
                        <a v-if="proctoring.sebLaunchUrl" :href="proctoring.sebLaunchUrl" class="font-medium underline">open this quiz in Safe Exam Browser</a>
                        <span v-else>open this quiz from it with the settings file your trainer shared</span>.
                    </p>
                </div>

                <div v-if="quiz.rules?.length && (quiz.canAttempt || openAttemptId)" class="mt-5 rounded-[var(--radius-field)] border px-4 py-3" style="border-color: var(--border-subtle)">
                    <p class="text-sm font-semibold">Before you start</p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm" style="color: var(--text-base)">
                        <li v-for="rule in quiz.rules" :key="rule">{{ rule }}</li>
                    </ul>
                </div>

                <div v-if="proctoring && quiz.canAttempt && !openAttemptId && !blocked" class="mt-4">
                    <UiCheckbox v-model="agree" label="I have read how this quiz is proctored, and I agree." />
                    <p v-if="errors.agree" class="mt-1 text-xs" style="color: var(--color-danger-500)">{{ errors.agree }}</p>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <UiButton v-if="openAttemptId" :href="`/student/quizzes/${quiz.id}/attempts/${openAttemptId}`">
                        <template #leading><Hourglass class="h-4 w-4" /></template>
                        Carry on with your attempt
                    </UiButton>
                    <template v-else-if="quiz.canAttempt">
                        <UiButton :disabled="Boolean(proctoring) && (Boolean(blocked) || !agree)" :loading="starting" @click="start">
                            <template #leading><Play class="h-4 w-4" /></template>
                            {{ proctoring && !proctoring.requireSeb ? 'Start in full screen' : 'Start the quiz' }}
                        </UiButton>
                        <p v-if="blocked" class="text-sm" style="color: var(--text-muted)">{{ blocked }}</p>
                    </template>
                    <p v-else class="text-sm" style="color: var(--text-muted)">
                        {{ quiz.closedReason || 'No attempts left on this one.' }}
                    </p>
                </div>

                <p v-if="errors.quiz" class="mt-3 text-sm" role="alert" style="color: var(--color-danger-500)">{{ errors.quiz }}</p>
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

                        <UiBadge v-if="attempt.voided" tone="warning" size="sm">does not count</UiBadge>
                        <UiBadge
                            v-else-if="attempt.needsReview"
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
