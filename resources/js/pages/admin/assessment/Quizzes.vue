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
    quizzes: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/courses/${props.course.id}`);

const open = ref(false);
const editing = ref(null);

const form = useForm({
    title: '',
    instructions: '',
    course_module_id: null,
    time_limit_minutes: null,
    attempts_allowed: 1,
    pass_percent: 50,
    shuffle_questions: false,
    show_answers: true,
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
    form.time_limit_minutes = quiz?.timeLimit ?? null;
    form.attempts_allowed = quiz?.attemptsAllowed ?? 1;
    form.pass_percent = quiz?.passPercent ?? 50;
    form.shuffle_questions = quiz?.shuffle ?? false;
    form.show_answers = quiz?.showAnswers ?? true;
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
        <div class="mx-auto max-w-4xl space-y-5">
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
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <UiButton :href="`${base}/quizzes/${quiz.id}`" variant="secondary" size="xs">
                            Questions
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="openForm(quiz)">
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="remove(quiz)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
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
                <UiSwitch
                    v-model="form.show_answers"
                    label="Show the answers afterwards"
                    description="Turn this off while a batch is still sitting it."
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
