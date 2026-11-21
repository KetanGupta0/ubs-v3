<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Pencil, Trash2, ClipboardList, AlertCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    quizzes: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/courses/${props.course.id}`);

const open = ref(false);
const editing = ref(null);

const form = useForm({
    title: '',
    instructions: '',
    course_module_id: null,
    batch_id: null,
    time_limit_minutes: null,
    attempts_allowed: 1,
    pass_percent: 50,
    shuffle_questions: false,
    pool_size: null,
    shuffle_options: false,
    one_at_a_time: false,
    allow_back: true,
    show_answers: true,
    answers_after_close: false,
    opens_at: '',
    closes_at: '',
    is_published: false,
});

function openForm(quiz = null) {
    editing.value = quiz;
    form.clearErrors();
    form.title = quiz?.title ?? '';
    form.instructions = quiz?.instructions ?? '';
    form.course_module_id = quiz?.moduleId ?? null;
    form.batch_id = quiz?.batchId ?? null;
    form.time_limit_minutes = quiz?.timeLimit ?? null;
    form.attempts_allowed = quiz?.attemptsAllowed ?? 1;
    form.pass_percent = quiz?.passPercent ?? 50;
    form.shuffle_questions = quiz?.shuffle ?? false;
    form.pool_size = quiz?.poolSize ?? null;
    form.shuffle_options = quiz?.shuffleOptions ?? false;
    form.one_at_a_time = quiz?.oneAtATime ?? false;
    form.allow_back = quiz?.allowBack ?? true;
    form.show_answers = quiz?.showAnswers ?? true;
    form.answers_after_close = quiz?.answersAfterClose ?? false;
    form.opens_at = quiz?.opensAt ?? '';
    form.closes_at = quiz?.closesAt ?? '';
    form.is_published = quiz?.isPublished ?? false;
    open.value = true;
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
        ? form.put(`${base.value}/quizzes/${editing.value.id}`, done)
        : form.post(`${base.value}/quizzes`, done);
}

function remove(quiz) {
    if (confirm(`Remove "${quiz.title}"?`)) {
        router.delete(`${base.value}/quizzes/${quiz.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${course.title} — quizzes`" />

    <AppLayout
        title="Quizzes"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: course.title, href: `/admin/courses/${course.id}/builder` },
            { label: 'Quizzes' },
        ]"
    >
        <div class="space-y-5">
            <PageHeader
                title="Quizzes"
                :description="`Assessment for ${course.title}. Timing is kept on the server, so a closed laptop does not buy anybody extra minutes.`"
            >
                <template #actions>
                    <UiButton :href="`${base}/builder`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Structure
                    </UiButton>
                    <UiButton size="sm" @click="openForm()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Quiz
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!quizzes.length"
                :icon="ClipboardList"
                title="No quizzes yet"
                description="A quiz can gate a lesson, so it is worth adding one before the batch starts."
            >
                <template #action>
                    <UiButton @click="openForm()">Add a quiz</UiButton>
                </template>
            </UiEmptyState>

            <UiCard v-for="quiz in quizzes" :key="quiz.id" padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ quiz.title }}
                            <UiBadge v-if="!quiz.isPublished" tone="warning" size="sm">draft</UiBadge>
                            <UiBadge v-if="quiz.proctoring && quiz.proctoring !== 'off'" tone="brand" size="sm">proctored</UiBadge>
                            <UiBadge v-if="quiz.awaitingMarking" tone="accent" size="sm">
                                <AlertCircle class="mr-1 h-3 w-3" />{{ quiz.awaitingMarking }} to mark
                            </UiBadge>
                        </h2>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            {{ quiz.questions }} questions · {{ quiz.totalMarks }} marks ·
                            pass at {{ quiz.passPercent }}% ·
                            {{ quiz.attemptsAllowed }} attempt{{ quiz.attemptsAllowed === 1 ? '' : 's' }}
                            <span v-if="quiz.timeLimit"> · {{ quiz.timeLimit }} min</span>
                            <span v-if="quiz.attempts"> · {{ quiz.attempts }} sat</span>
                            <span v-if="quiz.batch"> · {{ quiz.batch }} only</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <UiButton :href="`${base}/quizzes/${quiz.id}`" variant="secondary" size="xs">
                            Questions
                        </UiButton>
                        <UiButton :href="`/admin/proctoring/quizzes/${quiz.id}`" variant="ghost" size="xs">
                            Proctoring
                        </UiButton>
                        <UiButton :href="`${base}/quizzes/${quiz.id}/integrity`" variant="ghost" size="xs">
                            Integrity
                            <UiBadge v-if="quiz.flagged" tone="warning" size="sm" class="ml-1">{{ quiz.flagged }}</UiBadge>
                        </UiButton>
                        <UiButton variant="ghost" size="xs" :aria-label="`Settings for ${quiz.title}`" @click="openForm(quiz)">
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" :aria-label="`Delete ${quiz.title}`" @click="remove(quiz)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--text-danger)" />
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>

        <UiModal :open="open" size="lg" :title="editing ? 'Edit quiz' : 'New quiz'" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <UiFormField label="Title" required :error="form.errors.title">
                    <UiInput v-model="form.title" />
                </UiFormField>

                <UiFormField label="Instructions" :error="form.errors.instructions">
                    <UiTextarea v-model="form.instructions" :rows="3" />
                </UiFormField>

                <UiFormField label="Belongs to module" :error="form.errors.course_module_id">
                    <UiSelect v-model="form.course_module_id">
                        <option :value="null">Whole course</option>
                        <option v-for="module in modules" :key="module.value" :value="module.value">
                            {{ module.label }}
                        </option>
                    </UiSelect>
                </UiFormField>

                <UiFormField v-if="batches.length" label="Set for" hint="One batch, or everybody on the course." :error="form.errors.batch_id">
                    <UiSelect v-model="form.batch_id">
                        <option :value="null">Every batch</option>
                        <option v-for="batch in batches" :key="batch.value" :value="batch.value">{{ batch.label }}</option>
                    </UiSelect>
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Time limit (min)" :error="form.errors.time_limit_minutes">
                        <UiInput v-model.number="form.time_limit_minutes" type="number" min="1" />
                    </UiFormField>

                    <UiFormField label="Attempts allowed" required :error="form.errors.attempts_allowed">
                        <UiInput v-model.number="form.attempts_allowed" type="number" min="1" max="20" />
                    </UiFormField>

                    <UiFormField label="Pass mark %" required :error="form.errors.pass_percent">
                        <UiInput v-model.number="form.pass_percent" type="number" min="1" max="100" />
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Opens" :error="form.errors.opens_at">
                        <UiInput v-model="form.opens_at" type="datetime-local" />
                    </UiFormField>

                    <UiFormField label="Closes" :error="form.errors.closes_at">
                        <UiInput v-model="form.closes_at" type="datetime-local" />
                    </UiFormField>
                </div>

                <UiSwitch v-model="form.shuffle_questions" label="Shuffle the questions" />
                <UiSwitch v-model="form.shuffle_options" label="Shuffle the options" description="Each student sees the choices in their own order." />
                <UiFormField label="Questions each student gets" hint="Drawn from all of this quiz's questions, so neighbours get different papers. Empty: all of them." :error="form.errors.pool_size">
                    <UiInput v-model.number="form.pool_size" type="number" min="1" max="500" class="max-w-32" />
                </UiFormField>
                <UiSwitch v-model="form.one_at_a_time" label="One question at a time" />
                <UiSwitch v-if="form.one_at_a_time" v-model="form.allow_back" label="Let students go back" description="Off, an answer is final once they move on." />
                <UiSwitch
                    v-model="form.show_answers"
                    label="Show the answers afterwards"
                    description="Turn this off while a batch is still sitting it."
                />
                <UiSwitch
                    v-if="form.show_answers"
                    v-model="form.answers_after_close"
                    label="Only once the quiz closes for everybody"
                    description="So the first to finish cannot pass them on. Needs a closing time."
                />
                <UiSwitch v-model="form.is_published" label="Visible to students" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton :loading="form.processing" @click="save">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
