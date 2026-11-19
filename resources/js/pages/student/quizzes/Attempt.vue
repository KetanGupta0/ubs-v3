<script setup>
/**
 * Sitting the quiz.
 *
 * The countdown here is a courtesy. The deadline that decides anything is the
 * one the server wrote onto the attempt, which is why running out submits what
 * has been answered rather than trusting this page to be honest about it.
 *
 * Each answer is kept as it is given, with the time since the one before, and
 * the page reports what only it can see: leaving the quiz, pasting, copying
 * (§9.1). The student was told all of this before starting. None of it is a
 * verdict; a person looks at anything unusual.
 *
 * A proctored attempt (§9.1a) is also locked down (`useLockdown`) and sends a
 * heartbeat every few seconds, which carries back the proctor's messages, any
 * time they added, and whether the attempt has been ended.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, ArrowRight, Clock, Eye, MonitorSmartphone, Send, ShieldAlert } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import ExamLayout from '@/layouts/ExamLayout.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { useLockdown } from '@/composables/useLockdown';

const props = defineProps({
    quiz: { type: Object, required: true },
    attempt: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    saved: { type: Object, default: () => ({}) },
    proctoring: { type: Object, default: null },
});

const base = `/student/quizzes/${props.quiz.id}/attempts/${props.attempt.id}`;
const form = useForm({ answers: { ...props.saved } });
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/* ------------------------------------------------------ proctored (§9.1a) */

const proctored = Boolean(props.proctoring) && !props.attempt.elsewhere;
const lockdown = useLockdown({ enabled: proctored, needsFullscreen: !props.proctoring?.inSeb, report, flush: flushEvents });
const covered = lockdown.covered;
const exits = ref(props.proctoring?.exits ?? 0);
const maxExits = props.proctoring?.maxExits ?? null;
const messages = ref([...(props.proctoring?.messages ?? [])]);
const dismissed = new Set();
let finishing = false;
let moving = false; // to the next question or the result, on purpose
const Layout = proctored ? ExamLayout : AppLayout;

// Leaving shows at once on the cover; the heartbeat then brings the server's count.
watch(covered, (now) => now && (exits.value += 1));

const coverTitle = computed(() => ({
    fullscreen: 'Return to full screen to carry on',
    tab: 'You left the quiz',
    window: 'You left the quiz window',
})[lockdown.why.value] ?? 'You left the quiz');

const exitsLeft = computed(() => (maxExits ? Math.max(0, maxExits - exits.value) : null));

function acknowledge(message) {
    dismissed.add(message.id);
    messages.value = messages.value.filter((item) => item.id !== message.id);
    report({ type: 'message_seen', message: message.id });
    flushEvents();
}

/** What the heartbeat heard back: the clock, the count, messages, or the end. */
function heard(data) {
    if (data.ended) return finished(data.review);

    if (typeof data.secondsRemaining === 'number' && Math.abs(data.secondsRemaining - (remaining.value ?? 0)) > 2) {
        remaining.value = data.secondsRemaining;
    }
    if (typeof data.exits === 'number') exits.value = data.exits;

    for (const message of data.messages ?? []) {
        if (!dismissed.has(message.id) && !messages.value.some((item) => item.id === message.id)) messages.value.push(message);
    }
}

/** Ended by the proctor, for leaving too often, or the time: out of full screen and to the result. */
async function finished(review) {
    if (finishing) return;
    finishing = true;
    clearInterval(flusher);
    clearInterval(ticker);
    await lockdown.release();
    router.visit(review);
}

/* ------------------------------------------------------------ layout */

// No going back: the server sends only the question in hand.
const locked = computed(() => props.quiz.oneAtATime && !props.quiz.allowBack);
const stepping = computed(() => props.quiz.oneAtATime && props.quiz.allowBack);
const step = ref(0);
const visible = computed(() => (stepping.value ? props.questions.slice(step.value, step.value + 1) : props.questions));
const numberOf = (index) => (locked.value ? props.attempt.currentIndex + 1 : stepping.value ? step.value + 1 : index + 1);
const isLast = computed(() => (locked.value ? props.attempt.currentIndex >= props.attempt.total - 1 : step.value >= props.questions.length - 1));

const filled = (value) => value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length);
const answered = computed(() => Object.values(form.answers).filter(filled).length);

/* ------------------------------------------------------------ the clock */

const remaining = ref(props.attempt.secondsRemaining);
let ticker = null;

// Moving on reloads this page with the next question, and Inertia may keep this
// component: take the server's clock and what it kept, rather than the old ones.
watch(() => props.attempt.secondsRemaining, (seconds) => (remaining.value = seconds));
watch(() => props.saved, (saved) => Object.assign(form.answers, saved));

const clock = computed(() => {
    if (remaining.value === null) return null;
    const total = Math.max(0, remaining.value);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});
const running = computed(() => remaining.value !== null && remaining.value > 60);

/* ----------------------------------------------- answers, kept as given */

let lastMark = Date.now();
const timers = {};

function secondsSinceMark() {
    const seconds = Math.round((Date.now() - lastMark) / 1000);
    lastMark = Date.now();

    return seconds;
}

async function saveNow(questionId) {
    clearTimeout(timers[questionId]);
    delete timers[questionId];
    try {
        const response = await fetch(`${base}/answers`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ question_id: questionId, response: form.answers[questionId] ?? null, seconds: secondsSinceMark() }),
        });

        // Carried on in another browser since: show that, rather than answer into nothing.
        if (response.status === 409) {
            moving = true;
            router.reload();
        }
    } catch {
        // Kept on the page and sent with the submission anyway.
    }
}

function saveSoon(questionId, wait = 800) {
    clearTimeout(timers[questionId]);
    timers[questionId] = setTimeout(() => saveNow(questionId), wait);
}

function setSingle(questionId, value) {
    form.answers[questionId] = value;
    saveSoon(questionId, 300);
}

function setText(questionId, value) {
    form.answers[questionId] = value;
    saveSoon(questionId, 1500);
}

function toggleMulti(questionId, value) {
    const current = form.answers[questionId] ?? [];
    form.answers[questionId] = current.includes(value) ? current.filter((item) => item !== value) : [...current, value];
    saveSoon(questionId, 600);
}

/* -------------------------------------------------------------- moving */

function go(delta) {
    const current = visible.value[0];
    if (current && timers[current.id]) saveNow(current.id); // a change not yet sent
    step.value = Math.min(Math.max(0, step.value + delta), props.questions.length - 1);
}

function next() {
    const current = props.questions[0];
    clearTimeout(timers[current.id]); // sent now, with the answer
    moving = true;
    router.post(`${base}/next`, { question_id: current.id, response: form.answers[current.id] ?? null, seconds: secondsSinceMark() }, {
        preserveScroll: false,
        onFinish: () => (moving = false),
    });
}

function submit() {
    Object.values(timers).forEach(clearTimeout); // the submission carries every answer
    flushEvents(true);
    moving = true;
    form.transform((data) => ({ answers: data.answers })).post(base, {
        onSuccess: () => proctored && lockdown.release(),
        onFinish: () => (moving = false),
    });
}

/* ---------------------------------------- what only the page can see */

const buffer = [];
let flusher = null;
let awaySince = null;

function report(event) {
    buffer.push(event);
    if (buffer.length >= 10) flushEvents();
}

// On a proctored attempt this goes every few seconds, empty or not: it is the
// heartbeat that tells the proctor the student is still there.
async function flushEvents(leaving = false) {
    if (!buffer.length && !proctored) return;
    const events = buffer.splice(0, buffer.length);

    try {
        const response = await fetch(`${base}/events`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            keepalive: leaving,
            body: JSON.stringify({ events, state: proctored ? lockdown.state() : null }),
        });

        if (proctored && !leaving && response.ok) heard(await response.json());
    } catch {
        // The next beat tries again.
    }
}

function wentAway() {
    if (awaySince !== null) return;
    awaySince = Date.now();
    report({ type: 'focus_lost' });
    flushEvents(true);
}

function cameBack() {
    if (awaySince === null) return;
    report({ type: 'focus_back', seconds: Math.round((Date.now() - awaySince) / 1000) });
    awaySince = null;
    flushEvents();
}

const onVisibility = () => (document.visibilityState === 'hidden' ? wentAway() : cameBack());
const onBlur = () => wentAway();
const onFocus = () => cameBack();
const onLeave = () => flushEvents(true);

function onPaste(questionId, event) {
    if (proctored) return; // switched off, and reported as tried
    const text = event.clipboardData?.getData('text') ?? '';
    report({ type: 'paste', question_id: questionId, length: text.length });
}

function onCopy(questionId) {
    if (proctored) return;
    const length = window.getSelection()?.toString().length ?? 0;
    if (length > 0) report({ type: 'copy', question_id: questionId, length });
}

/* ------------------------------------------------------- carry on here */

const takingOver = ref(false);
function carryOnHere() {
    takingOver.value = true;
    router.post(`${base}/here`, {}, { onFinish: () => (takingOver.value = false) });
}

onMounted(() => {
    if (props.attempt.elsewhere) return;

    // Proctored, the lockdown watches leaving, and says why.
    if (!proctored) {
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
    }
    window.addEventListener('pagehide', onLeave);
    flusher = setInterval(() => flushEvents(), 5000);
    if (proctored) flushEvents();

    if (remaining.value !== null) {
        ticker = setInterval(() => {
            remaining.value = Math.max(0, remaining.value - 1);
            // Out of time submits what is there. The server would mark it the
            // same way on the next request; doing it here avoids the surprise.
            if (remaining.value === 0 && !finishing) {
                clearInterval(ticker);
                submit();
            }
        }, 1000);
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('blur', onBlur);
    window.removeEventListener('focus', onFocus);
    window.removeEventListener('pagehide', onLeave);
    clearInterval(flusher);
    clearInterval(ticker);

    // Proctored, and the page went without the attempt finishing or moving on:
    // that is leaving the quiz, whichever way it happened.
    if (proctored && !finishing && !moving) report({ type: 'focus_lost', why: 'tab' });
    if (!finishing) flushEvents(true);
});
</script>

<template>
    <Head :title="quiz.title" />

    <component :is="Layout" :title="quiz.title">
        <!-- Proctored: the questions are covered while the student is away (§9.1a). -->
        <div
            v-if="proctored && covered"
            class="fixed inset-0 z-50 flex items-center justify-center p-6"
            style="background: var(--surface-sunken)"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="cover-title"
        >
            <div class="max-w-md text-center">
                <ShieldAlert class="mx-auto h-10 w-10" style="color: var(--color-warn-500)" />
                <p id="cover-title" class="mt-4 text-lg font-semibold">{{ coverTitle }}</p>
                <p class="mt-2 text-sm" style="color: var(--text-muted)">
                    The questions are covered until you come back. Leaving is logged, the proctor can see it, and the clock keeps running.
                </p>
                <p class="mt-3 text-sm font-medium">
                    Left {{ exits }} {{ exits === 1 ? 'time' : 'times' }}.
                    <template v-if="exitsLeft === 0">That is as often as this quiz allows, so it is being submitted.</template>
                    <template v-else-if="exitsLeft !== null">{{ exitsLeft }} more and it is submitted with what you have answered.</template>
                </p>
                <UiButton class="mt-6" @click="lockdown.returnToFullscreen()">
                    {{ lockdown.why.value === 'fullscreen' ? 'Return to full screen' : 'Back to the quiz' }}
                </UiButton>
            </div>
        </div>

        <!-- A message from the proctor shows over everything, until it is read. -->
        <div
            v-if="proctored && messages.length"
            class="fixed inset-x-0 top-4 z-[60] mx-auto w-[min(32rem,calc(100%-2rem))] rounded-[var(--radius-card)] border-2 p-4 shadow-lg"
            style="background: var(--surface-raised); border-color: var(--color-brand-500)"
            role="alertdialog"
            aria-labelledby="proctor-message"
        >
            <p class="text-xs font-semibold" style="color: var(--color-brand-500)">Message from the proctor · {{ messages[0].at }}</p>
            <p id="proctor-message" class="mt-1.5 text-sm" style="color: var(--text-strong)">{{ messages[0].text }}</p>
            <UiButton class="mt-3" size="sm" @click="acknowledge(messages[0])">OK</UiButton>
        </div>

        <!-- Something switched off was tried: said, briefly. -->
        <div
            v-if="proctored && lockdown.warning.value"
            class="fixed inset-x-0 bottom-24 z-[55] mx-auto w-fit max-w-[90vw] rounded-full px-4 py-2 text-sm font-medium shadow-lg sm:bottom-8"
            style="background: var(--surface-inverse); color: var(--text-inverse)"
            role="status"
        >
            {{ lockdown.warning.value }}
        </div>

        <div class="mx-auto max-w-3xl space-y-5">
            <!-- Open in another browser: one place at a time. -->
            <UiCard v-if="attempt.elsewhere">
                <div class="flex items-start gap-3">
                    <MonitorSmartphone class="mt-0.5 h-5 w-5 shrink-0" style="color: var(--text-muted)" />
                    <div>
                        <p class="text-sm font-semibold">This attempt is open in another browser</p>
                        <p class="mt-1 text-sm" style="color: var(--text-muted)">
                            You can carry on here instead. That is noted on the attempt, and the other browser stops being able to save.
                        </p>
                        <UiButton class="mt-4" :loading="takingOver" @click="carryOnHere">Carry on here</UiButton>
                    </div>
                </div>
            </UiCard>

            <template v-else>
                <!-- ------------------------------------------- the clock -->
                <div
                    class="sticky top-2 z-10 flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border px-4 py-3"
                    :style="{ borderColor: clock && !running ? 'var(--color-danger-500)' : 'var(--border-subtle)', background: 'var(--surface)' }"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ quiz.title }}
                            <UiBadge v-if="proctored" tone="brand" size="sm" :title="'A member of staff can see whether you are in the quiz and answering. Not your screen, not your camera, and nothing is recorded.'">
                                <Eye class="mr-1 h-3 w-3" aria-hidden="true" />
                                Proctored
                            </UiBadge>
                            <UiBadge v-if="proctored && exits" tone="warning" size="sm">Left {{ exits }}{{ maxExits ? ` of ${maxExits}` : '' }}</UiBadge>
                        </p>
                        <p class="text-xs" style="color: var(--text-muted)">
                            Attempt {{ attempt.number }} ·
                            <template v-if="quiz.oneAtATime">question {{ locked ? attempt.currentIndex + 1 : step + 1 }} of {{ attempt.total }}</template>
                            <template v-else>{{ answered }} of {{ attempt.total }} answered</template>
                        </p>
                    </div>
                    <div v-if="clock" class="flex items-center gap-2">
                        <Clock class="h-4 w-4" :style="{ color: running ? 'var(--text-muted)' : 'var(--color-danger-500)' }" />
                        <span class="text-lg font-semibold tnum" :style="{ color: running ? 'var(--text-strong)' : 'var(--color-danger-500)' }">{{ clock }}</span>
                    </div>
                </div>

                <p v-if="quiz.instructions" class="rounded-[var(--radius-card)] border px-4 py-3 text-sm" style="border-color: var(--border-subtle); color: var(--text-muted)">
                    {{ quiz.instructions }}
                </p>

                <!-- -------------------------------------------- questions -->
                <form class="space-y-4" @submit.prevent="submit">
                    <UiCard v-for="(question, index) in visible" :key="question.id">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-medium" @copy="onCopy(question.id)">
                                <span style="color: var(--text-muted)">{{ numberOf(index) }}.</span>
                                {{ question.body }}
                            </p>
                            <UiBadge size="sm">{{ question.marks }} {{ question.marks === 1 ? 'mark' : 'marks' }}</UiBadge>
                        </div>

                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ question.typeLabel }}</p>

                        <!-- one answer -->
                        <div v-if="question.type === 'mcq'" class="mt-3 space-y-2" @copy="onCopy(question.id)">
                            <label
                                v-for="(option, optionIndex) in question.options"
                                :key="`${optionIndex}-${option}`"
                                class="flex cursor-pointer items-start gap-2.5 rounded-[var(--radius-field)] border px-3 py-2.5 text-sm transition"
                                :style="{ borderColor: form.answers[question.id] === option ? 'var(--color-brand-500)' : 'var(--border-subtle)' }"
                            >
                                <input
                                    type="radio"
                                    :name="`q-${question.id}`"
                                    :value="option"
                                    :checked="form.answers[question.id] === option"
                                    class="mt-0.5"
                                    @change="setSingle(question.id, option)"
                                >
                                <span>{{ option }}</span>
                            </label>
                        </div>

                        <!-- several answers -->
                        <div v-else-if="question.type === 'multi'" class="mt-3 space-y-2" @copy="onCopy(question.id)">
                            <div
                                v-for="(option, optionIndex) in question.options"
                                :key="`${optionIndex}-${option}`"
                                class="rounded-[var(--radius-field)] border px-3 py-2.5 transition"
                                :style="{ borderColor: (form.answers[question.id] ?? []).includes(option) ? 'var(--color-brand-500)' : 'var(--border-subtle)' }"
                            >
                                <UiCheckbox
                                    :model-value="(form.answers[question.id] ?? []).includes(option)"
                                    :label="option"
                                    @update:model-value="toggleMulti(question.id, option)"
                                />
                            </div>
                            <p class="text-xs" style="color: var(--text-muted)">Pick every one that applies. Part marks are not awarded.</p>
                        </div>

                        <!-- true or false -->
                        <div v-else-if="question.type === 'truefalse'" class="mt-3 flex gap-2">
                            <UiButton
                                v-for="choice in ['true', 'false']"
                                :key="choice"
                                type="button"
                                :variant="form.answers[question.id] === choice ? 'primary' : 'secondary'"
                                size="sm"
                                @click="setSingle(question.id, choice)"
                            >
                                {{ choice === 'true' ? 'True' : 'False' }}
                            </UiButton>
                        </div>

                        <!-- written -->
                        <div v-else class="mt-3">
                            <UiTextarea
                                :model-value="form.answers[question.id] ?? ''"
                                :rows="4"
                                placeholder="Your answer…"
                                @update:model-value="setText(question.id, $event)"
                                @paste="onPaste(question.id, $event)"
                            />
                            <p class="mt-1.5 text-xs" style="color: var(--text-muted)">A person marks this one, so it will not be scored straight away.</p>
                        </div>
                    </UiCard>

                    <UiCard>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="flex items-center gap-2 text-sm" style="color: var(--text-muted)">
                                <AlertTriangle class="h-4 w-4" style="color: var(--color-warn-500)" />
                                <template v-if="locked && !isLast">Once you move on, this answer is final.</template>
                                <template v-else>Once you submit, this attempt is closed.</template>
                            </p>

                            <div class="flex flex-wrap gap-2">
                                <UiButton v-if="stepping && step > 0" type="button" variant="secondary" @click="go(-1)">
                                    <template #leading><ArrowLeft class="h-4 w-4" /></template>
                                    Back
                                </UiButton>
                                <UiButton v-if="stepping && !isLast" type="button" @click="go(1)">
                                    Next
                                    <template #trailing><ArrowRight class="h-4 w-4" /></template>
                                </UiButton>
                                <UiButton v-if="locked && !isLast" type="button" @click="next">
                                    Next question
                                    <template #trailing><ArrowRight class="h-4 w-4" /></template>
                                </UiButton>
                                <UiButton v-if="!quiz.oneAtATime || isLast" type="submit" :loading="form.processing">
                                    <template #leading><Send class="h-4 w-4" /></template>
                                    {{ quiz.oneAtATime ? 'Submit' : `Submit ${answered} of ${attempt.total}` }}
                                </UiButton>
                            </div>
                        </div>
                    </UiCard>
                </form>
            </template>
        </div>
    </component>
</template>
