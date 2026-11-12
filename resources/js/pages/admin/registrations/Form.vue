<script setup>
/**
 * Build or change a registration form: which course, when it is open, what it
 * asks, whether the fee applies and whether somebody approves each one.
 */
import { computed, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    form: { type: Object, default: null },
    courses: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    standardFields: { type: Array, default: () => [] },
    questionTypes: { type: Array, default: () => [] },
    maxQuestions: { type: Number, default: 15 },
});

const editing = computed(() => props.form !== null);

const data = useForm({
    title: props.form?.title ?? '',
    slug: props.form?.slug ?? '',
    intro: props.form?.intro ?? '',
    course_id: props.form?.course_id ?? null,
    batch_id: props.form?.batch_id ?? '',
    opens_at: props.form?.opens_at ?? '',
    closes_at: props.form?.closes_at ?? '',
    capacity: props.form?.capacity ?? '',
    fee: props.form?.fee ?? 'course',
    approval: props.form?.approval ?? 'review',
    fields: props.form?.fields ?? [],
    questions: (props.form?.questions ?? []).map((question) => ({ ...question, options: [...(question.options ?? [])] })),
    is_active: props.form?.is_active ?? true,
});

const courseBatches = computed(() => props.batches.filter((batch) => batch.courseId === data.course_id));
const batchOptions = computed(() => [{ value: '', label: 'Decide per person' }, ...courseBatches.value]);
const selectedCourse = computed(() => props.courses.find((course) => course.value === data.course_id));

// A batch of another course is not a choice once the course changes.
watch(() => data.course_id, () => {
    if (data.batch_id && !courseBatches.value.some((batch) => String(batch.value) === String(data.batch_id))) {
        data.batch_id = '';
    }
});

const feeOptions = computed(() => [
    { value: 'course', label: selectedCourse.value?.description?.startsWith('Free') ? 'The course is free' : 'The course fee applies' },
    { value: 'free', label: 'Free for everybody who registers here' },
]);

const approvalOptions = [
    { value: 'review', label: 'Somebody on the team approves each one' },
    { value: 'auto', label: 'On the course as soon as the address is confirmed' },
];

function addQuestion() {
    if (data.questions.length >= props.maxQuestions) return;
    data.questions.push({ key: '', label: '', type: 'short', required: false, options: [] });
}

function removeQuestion(index) {
    data.questions.splice(index, 1);
}

function moveQuestion(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= data.questions.length) return;
    const [question] = data.questions.splice(index, 1);
    data.questions.splice(target, 0, question);
}

function optionsText(question) {
    return question.options.join('\n');
}

function setOptions(question, text) {
    question.options = text.split('\n');
}

function save() {
    const transform = (payload) => ({
        ...payload,
        batch_id: payload.batch_id || null,
        capacity: payload.capacity === '' ? null : payload.capacity,
        opens_at: payload.opens_at || null,
        closes_at: payload.closes_at || null,
        questions: payload.questions.map((question) => ({
            ...question,
            options: question.type === 'choice' ? question.options.map((option) => option.trim()).filter(Boolean) : [],
        })),
    });

    if (editing.value) {
        data.transform(transform).put(`/admin/registration-forms/${props.form.id}`, { preserveScroll: true });
    } else {
        data.transform(transform).post('/admin/registration-forms', { preserveScroll: true });
    }
}

function destroy() {
    if (window.confirm('Delete this form? Its address and QR code stop working.')) {
        router.delete(`/admin/registration-forms/${props.form.id}`);
    }
}
</script>

<template>
    <Head :title="editing ? form.title : 'New registration form'" />

    <AppLayout
        :title="editing ? form.title : 'New registration form'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Registration forms', href: '/admin/registration-forms' },
            { label: editing ? form.title : 'New' },
        ]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader
                :title="editing ? 'Change the form' : 'New registration form'"
                description="Anybody can fill it without an account. New addresses get an account and their sign in details; an existing student has to confirm from their own account first."
            >
                <template #actions>
                    <UiButton :href="editing ? `/admin/registration-forms/${form.id}` : '/admin/registration-forms'" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <form class="space-y-5" @submit.prevent="save">
                <FormSection title="The form">
                    <UiFormField label="Title" required hint="The heading people see, for example: Summer internship, MIT Pune." :error="data.errors.title">
                        <UiInput v-model="data.title" />
                    </UiFormField>
                    <UiFormField
                        label="Address"
                        :hint="`It will be at /join/${data.slug || 'made-from-the-title'}. Keep it short; it goes on posters.`"
                        :error="data.errors.slug"
                    >
                        <UiInput v-model="data.slug" placeholder="made from the title if left empty" />
                    </UiFormField>
                    <UiFormField label="Introduction" hint="Optional. Shown above the form. Markdown: **bold**, lists, links." :error="data.errors.intro">
                        <UiTextarea v-model="data.intro" :rows="4" />
                    </UiFormField>
                </FormSection>

                <FormSection title="Course" description="A course that is LMS only can have a form; the form is the only public place it appears.">
                    <UiFormField label="Course" required :error="data.errors.course_id">
                        <UiCombobox v-model="data.course_id" :options="courses" placeholder="Choose a course" :disabled="form?.hasRegistrations" />
                    </UiFormField>
                    <p v-if="form?.hasRegistrations" class="-mt-2 text-xs" style="color: var(--text-muted)">
                        People have registered through this form, so its course stays as it is.
                    </p>
                    <UiFormField label="Batch" hint="Optional. Without one, put each person on a batch when you approve them." :error="data.errors.batch_id">
                        <UiSelect v-model="data.batch_id" :options="batchOptions" :disabled="!data.course_id" />
                    </UiFormField>
                </FormSection>

                <FormSection title="When, and how many">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Opens" hint="Empty: open now." :error="data.errors.opens_at">
                            <UiInput v-model="data.opens_at" type="datetime-local" />
                        </UiFormField>
                        <UiFormField label="Closes" hint="Empty: open until switched off." :error="data.errors.closes_at">
                            <UiInput v-model="data.closes_at" type="datetime-local" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Places" hint="Empty: no limit. Rejected and declined registrations give their place back." :error="data.errors.capacity">
                        <UiInput v-model="data.capacity" type="number" min="1" class="max-w-40" />
                    </UiFormField>
                </FormSection>

                <FormSection title="Fee and approval">
                    <UiFormField label="Fee" :hint="selectedCourse ? `${selectedCourse.label}: ${selectedCourse.description}.` : null" :error="data.errors.fee">
                        <UiSelect v-model="data.fee" :options="feeOptions" />
                    </UiFormField>
                    <p class="-mt-2 text-xs" style="color: var(--text-muted)">
                        With a fee, people are put on the course unpaid and asked to pay; the lessons that need payment open once it is paid.
                    </p>
                    <UiFormField label="Approval" :error="data.errors.approval">
                        <UiSelect v-model="data.approval" :options="approvalOptions" />
                    </UiFormField>
                </FormSection>

                <FormSection title="What it asks" description="Name, email and mobile are always asked.">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <UiCheckbox
                            v-for="field in standardFields"
                            :key="field.value"
                            v-model="data.fields"
                            :value="field.value"
                            :label="field.label"
                        />
                    </div>

                    <p v-if="data.errors.fields" class="text-sm text-danger-600">{{ data.errors.fields }}</p>

                    <div class="space-y-3 pt-2">
                        <div
                            v-for="(question, index) in data.questions"
                            :key="index"
                            class="space-y-3 rounded-xl border p-4"
                            style="border-color: var(--border-subtle)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Question {{ index + 1 }}</span>
                                <div class="flex gap-1">
                                    <UiButton variant="ghost" size="xs" :aria-label="`Move question ${index + 1} up`" :disabled="index === 0" @click="moveQuestion(index, -1)">
                                        <ArrowUp class="h-3.5 w-3.5" />
                                    </UiButton>
                                    <UiButton variant="ghost" size="xs" :aria-label="`Move question ${index + 1} down`" :disabled="index === data.questions.length - 1" @click="moveQuestion(index, 1)">
                                        <ArrowDown class="h-3.5 w-3.5" />
                                    </UiButton>
                                    <UiButton variant="ghost" size="xs" :aria-label="`Remove question ${index + 1}`" @click="removeQuestion(index)">
                                        <Trash2 class="h-3.5 w-3.5" />
                                    </UiButton>
                                </div>
                            </div>
                            <UiFormField label="Question" required :error="data.errors[`questions.${index}.label`]">
                                <UiInput v-model="question.label" />
                            </UiFormField>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <UiFormField label="Answered as" :error="data.errors[`questions.${index}.type`]">
                                    <UiSelect v-model="question.type" :options="questionTypes" />
                                </UiFormField>
                                <div class="flex items-end pb-2">
                                    <UiCheckbox v-model="question.required" label="Must be answered" />
                                </div>
                            </div>
                            <UiFormField
                                v-if="question.type === 'choice'"
                                label="Answers to choose from"
                                hint="One per line."
                                :error="data.errors[`questions.${index}.options`]"
                            >
                                <UiTextarea :model-value="optionsText(question)" :rows="3" @update:model-value="setOptions(question, $event)" />
                            </UiFormField>
                        </div>

                        <p v-if="data.errors.questions" class="text-sm text-danger-600">{{ data.errors.questions }}</p>

                        <UiButton variant="secondary" size="sm" :disabled="data.questions.length >= maxQuestions" @click="addQuestion">
                            <template #leading><Plus class="h-3.5 w-3.5" /></template>
                            Add a question
                        </UiButton>
                    </div>
                </FormSection>

                <FormSection title="Switched on">
                    <UiSwitch v-model="data.is_active" label="Open to registrations" description="Off closes the form at once, whatever its dates say. The address keeps working and says it is closed." />
                </FormSection>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <UiButton v-if="editing && !form.hasRegistrations" variant="ghost" size="sm" @click="destroy">
                        <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                        Delete the form
                    </UiButton>
                    <span v-else />
                    <UiButton type="submit" :loading="data.processing">{{ editing ? 'Save' : 'Create the form' }}</UiButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
