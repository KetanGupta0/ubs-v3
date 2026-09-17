<script setup>
/**
 * The questions in one quiz.
 *
 * The answer key is edited next to the question rather than in a separate
 * "answers" screen, because a key that lives away from its question is how a
 * quiz ends up marking everybody wrong.
 */
import { ref, computed, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Pencil, Trash2, HelpCircle, AlertTriangle, Check } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    quiz: { type: Object, required: true },
    questions: { type: Array, default: () => [] },
    types: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/courses/${props.course.id}/quizzes/${props.quiz.id}`);

const open = ref(false);
const editing = ref(null);

const form = useForm({
    type: 'mcq',
    body: '',
    options: ['', ''],
    correct: [],
    explanation: '',
    marks: 1,
});

/** True or false writes its own options, so nobody can mistype "Ture". */
watch(() => form.type, (type) => {
    if (type === 'truefalse') {
        form.options = ['True', 'False'];
        form.correct = form.correct.filter((value) => ['True', 'False'].includes(value));
    }

    if (type === 'mcq' && form.correct.length > 1) {
        form.correct = form.correct.slice(0, 1);
    }
});

function openForm(question = null) {
    editing.value = question;
    form.clearErrors();
    form.type = question?.type ?? 'mcq';
    form.body = question?.body ?? '';
    form.options = question?.options?.length ? [...question.options] : ['', ''];
    form.correct = question?.correct ? [...question.correct] : [];
    form.explanation = question?.explanation ?? '';
    form.marks = question?.marks ?? 1;
    open.value = true;
}

function addOption() {
    if (form.options.length < 10) form.options.push('');
}

function removeOption(index) {
    const removed = form.options[index];
    form.options.splice(index, 1);
    form.correct = form.correct.filter((value) => value !== removed);
}

function toggleCorrect(option) {
    if (form.type === 'multi') {
        form.correct = form.correct.includes(option)
            ? form.correct.filter((value) => value !== option)
            : [...form.correct, option];
        return;
    }

    form.correct = [option];
}

function save() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            editing.value = null;
            form.reset();
        },
    };

    editing.value
        ? form.put(`${base.value}/questions/${editing.value.id}`, done)
        : form.post(`${base.value}/questions`, done);
}

function remove(question) {
    if (confirm('Remove this question?')) {
        router.delete(`${base.value}/questions/${question.id}`, { preserveScroll: true });
    }
}

const needsOptions = computed(() => ['mcq', 'multi', 'truefalse'].includes(form.type));

/** A choice question with no key marks everybody wrong, forever. */
const missingKey = computed(() => needsOptions.value && form.correct.length === 0);
</script>

<template>
    <Head :title="quiz.title" />

    <AppLayout
        :title="quiz.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: course.title, href: `/admin/courses/${course.id}/builder` },
            { label: 'Quizzes', href: `/admin/courses/${course.id}/quizzes` },
            { label: quiz.title },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader
                :title="quiz.title"
                :description="quiz.instructions || 'Add the questions, then publish the quiz.'"
            >
                <template #actions>
                    <UiButton :href="`/admin/courses/${course.id}/quizzes`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton size="sm" @click="openForm()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Question
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                <UiBadge size="sm">{{ questions.length }} questions</UiBadge>
                <UiBadge size="sm">{{ quiz.totalMarks }} marks</UiBadge>
                <UiBadge size="sm">pass at {{ quiz.passPercent }}%</UiBadge>
                <UiBadge v-if="!quiz.isPublished" tone="warning" size="sm">not published</UiBadge>
            </div>

            <p
                v-if="quiz.hasAttempts"
                class="flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-xs"
                style="background: var(--surface-sunken); color: var(--text-muted)"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                <span>
                    Students have already sat this quiz. Editing a question now changes what their marks were for,
                    so prefer adding a new question to rewriting an old one.
                </span>
            </p>

            <UiEmptyState
                v-if="!questions.length"
                :icon="HelpCircle"
                title="No questions yet"
                description="A published quiz with no questions passes everybody, so add them before publishing."
            >
                <template #action>
                    <UiButton @click="openForm()">Add the first question</UiButton>
                </template>
            </UiEmptyState>

            <UiCard v-for="(question, index) in questions" :key="question.id" padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ index + 1 }}. {{ question.body }}</p>

                        <ul v-if="question.options.length" class="mt-2 space-y-1">
                            <li
                                v-for="option in question.options"
                                :key="option"
                                class="flex items-center gap-1.5 text-xs"
                                :style="{
                                    color: question.correct.includes(option)
                                        ? 'var(--color-signal-600)'
                                        : 'var(--text-muted)',
                                }"
                            >
                                <Check v-if="question.correct.includes(option)" class="h-3 w-3 shrink-0" />
                                <span v-else class="h-3 w-3 shrink-0" />
                                {{ option }}
                            </li>
                        </ul>

                        <p v-else class="mt-2 text-xs" style="color: var(--text-muted)">
                            Written answer — marked by hand.
                        </p>

                        <p v-if="question.explanation" class="mt-2 text-xs" style="color: var(--text-muted)">
                            Explanation: {{ question.explanation }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <UiBadge size="sm">{{ question.typeLabel }}</UiBadge>
                        <UiBadge size="sm">{{ question.marks }} mark{{ question.marks === 1 ? '' : 's' }}</UiBadge>
                        <UiButton variant="ghost" size="xs" @click="openForm(question)">
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="remove(question)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>

        <!-- ---------------------------------------------- question form -->
        <UiModal
            :open="open"
            size="lg"
            :title="editing ? 'Edit question' : 'New question'"
            @close="open = false"
        >
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Type" required :error="form.errors.type">
                        <UiSelect v-model="form.type">
                            <option v-for="type in types" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </UiSelect>
                    </UiFormField>

                    <UiFormField label="Marks" required :error="form.errors.marks">
                        <UiInput v-model.number="form.marks" type="number" min="1" max="100" />
                    </UiFormField>
                </div>

                <UiFormField label="Question" required :error="form.errors.body">
                    <UiTextarea v-model="form.body" :rows="3" />
                </UiFormField>

                <div v-if="needsOptions">
                    <p class="mb-2 text-xs font-medium" style="color: var(--text-muted)">
                        Options — tick the correct
                        {{ form.type === 'multi' ? 'answers' : 'answer' }}
                    </p>

                    <ul class="space-y-2">
                        <li v-for="(option, index) in form.options" :key="index" class="flex items-center gap-2">
                            <UiCheckbox
                                :model-value="form.correct.includes(option) && option !== ''"
                                :disabled="option === ''"
                                :aria-label="`Mark option ${index + 1} correct`"
                                @update:model-value="toggleCorrect(option)"
                            />
                            <UiInput
                                v-model="form.options[index]"
                                :disabled="form.type === 'truefalse'"
                                :placeholder="`Option ${index + 1}`"
                                class="flex-1"
                            />
                            <UiButton
                                v-if="form.type !== 'truefalse' && form.options.length > 2"
                                variant="ghost"
                                size="xs"
                                aria-label="Remove option"
                                @click="removeOption(index)"
                            >
                                <Trash2 class="h-3.5 w-3.5" />
                            </UiButton>
                        </li>
                    </ul>

                    <UiButton
                        v-if="form.type !== 'truefalse'"
                        variant="ghost"
                        size="xs"
                        class="mt-2"
                        @click="addOption"
                    >
                        <template #leading><Plus class="h-3 w-3" /></template>
                        Option
                    </UiButton>

                    <p v-if="missingKey" class="mt-3 text-xs" style="color: var(--color-warn-600)">
                        Nothing is ticked. Saving it this way means every answer counts as wrong.
                    </p>
                </div>

                <UiFormField
                    label="Explanation"
                    hint="Shown after the attempt, if the quiz reveals answers."
                    :error="form.errors.explanation"
                >
                    <UiTextarea v-model="form.explanation" :rows="2" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton :loading="form.processing" @click="save">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
