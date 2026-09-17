<script setup>
/**
 * Sitting the quiz.
 *
 * The countdown here is a courtesy. The deadline that decides anything is the
 * one the server wrote onto the attempt, which is why running out submits what
 * has been answered rather than trusting this page to be honest about it.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Clock, AlertTriangle, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    attempt: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
});

const form = useForm({ answers: {} });

const remaining = ref(props.attempt.secondsRemaining);
let ticker = null;

const clock = computed(() => {
    if (remaining.value === null) return null;

    const total = Math.max(0, remaining.value);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
});

const running = computed(() => remaining.value !== null && remaining.value > 60);
const answered = computed(() => Object.values(form.answers).filter((v) => v !== null && v !== undefined && v !== '' && !(Array.isArray(v) && !v.length)).length);

onMounted(() => {
    if (remaining.value === null) return;

    ticker = setInterval(() => {
        remaining.value = Math.max(0, remaining.value - 1);

        // Out of time submits what is there. The server would mark it the same
        // way on the next request, so doing it here just avoids the surprise.
        if (remaining.value === 0) {
            clearInterval(ticker);
            submit();
        }
    }, 1000);
});

onBeforeUnmount(() => ticker && clearInterval(ticker));

function submit() {
    form.post(`/student/quizzes/${props.quiz.id}/attempts/${props.attempt.id}`);
}

function setSingle(questionId, value) {
    form.answers[questionId] = value;
}

function toggleMulti(questionId, value) {
    const current = form.answers[questionId] ?? [];

    form.answers[questionId] = current.includes(value)
        ? current.filter((item) => item !== value)
        : [...current, value];
}
</script>

<template>
    <Head :title="quiz.title" />

    <AppLayout :title="quiz.title">
        <div class="mx-auto max-w-3xl space-y-5">
            <!-- --------------------------------------------- the clock -->
            <div
                class="sticky top-2 z-10 flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border px-4 py-3"
                :style="{
                    borderColor: clock && !running ? 'var(--color-danger-500)' : 'var(--border-subtle)',
                    background: 'var(--surface)',
                }"
            >
                <div class="min-w-0">
                    <p class="text-sm font-semibold">{{ quiz.title }}</p>
                    <p class="text-xs" style="color: var(--text-muted)">
                        Attempt {{ attempt.number }} · {{ answered }} of {{ questions.length }} answered
                    </p>
                </div>

                <div v-if="clock" class="flex items-center gap-2">
                    <Clock class="h-4 w-4" :style="{ color: running ? 'var(--text-muted)' : 'var(--color-danger-500)' }" />
                    <span
                        class="text-lg font-semibold tnum"
                        :style="{ color: running ? 'var(--text-strong)' : 'var(--color-danger-500)' }"
                    >
                        {{ clock }}
                    </span>
                </div>
            </div>

            <p v-if="quiz.instructions" class="rounded-[var(--radius-card)] border px-4 py-3 text-sm"
               style="border-color: var(--border-subtle); color: var(--text-muted)">
                {{ quiz.instructions }}
            </p>

            <!-- ---------------------------------------------- questions -->
            <form class="space-y-4" @submit.prevent="submit">
                <UiCard v-for="(question, index) in questions" :key="question.id">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium">
                            <span style="color: var(--text-muted)">{{ index + 1 }}.</span>
                            {{ question.body }}
                        </p>
                        <UiBadge size="sm">{{ question.marks }} {{ question.marks === 1 ? 'mark' : 'marks' }}</UiBadge>
                    </div>

                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ question.typeLabel }}</p>

                    <!-- one answer -->
                    <div v-if="question.type === 'mcq'" class="mt-3 space-y-2">
                        <label
                            v-for="(option, optionIndex) in question.options"
                            :key="optionIndex"
                            class="flex cursor-pointer items-start gap-2.5 rounded-[var(--radius-field)] border px-3 py-2.5 text-sm transition"
                            :style="{
                                borderColor: form.answers[question.id] === String(optionIndex)
                                    ? 'var(--color-brand-500)'
                                    : 'var(--border-subtle)',
                            }"
                        >
                            <input
                                type="radio"
                                :name="`q-${question.id}`"
                                :value="String(optionIndex)"
                                class="mt-0.5"
                                @change="setSingle(question.id, String(optionIndex))"
                            >
                            <span>{{ option }}</span>
                        </label>
                    </div>

                    <!-- several answers -->
                    <div v-else-if="question.type === 'multi'" class="mt-3 space-y-2">
                        <div
                            v-for="(option, optionIndex) in question.options"
                            :key="optionIndex"
                            class="rounded-[var(--radius-field)] border px-3 py-2.5 transition"
                            :style="{
                                borderColor: (form.answers[question.id] ?? []).includes(String(optionIndex))
                                    ? 'var(--color-brand-500)'
                                    : 'var(--border-subtle)',
                            }"
                        >
                            <UiCheckbox
                                :model-value="(form.answers[question.id] ?? []).includes(String(optionIndex))"
                                :label="option"
                                @update:model-value="toggleMulti(question.id, String(optionIndex))"
                            />
                        </div>
                        <p class="text-xs" style="color: var(--text-muted)">
                            Pick every one that applies. Part marks are not awarded.
                        </p>
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
                            @update:model-value="setSingle(question.id, $event)"
                        />
                        <p class="mt-1.5 text-xs" style="color: var(--text-muted)">
                            A person marks this one, so it will not be scored straight away.
                        </p>
                    </div>
                </UiCard>

                <UiCard>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="flex items-center gap-2 text-sm" style="color: var(--text-muted)">
                            <AlertTriangle class="h-4 w-4" style="color: var(--color-warn-500)" />
                            Once you submit, this attempt is closed.
                        </p>

                        <UiButton type="submit" :loading="form.processing">
                            <template #leading><Send class="h-4 w-4" /></template>
                            Submit {{ answered }} of {{ questions.length }}
                        </UiButton>
                    </div>
                </UiCard>
            </form>
        </div>
    </AppLayout>
</template>
