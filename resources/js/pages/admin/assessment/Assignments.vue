<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Pencil, Trash2, FileText, AlertCircle } from 'lucide-vue-next';

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
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    assignments: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/courses/${props.course.id}`);

const open = ref(false);
const editing = ref(null);

const form = useForm({
    title: '',
    brief: '',
    checklist: [],
    course_module_id: null,
    batch_id: null,
    due_at: '',
    max_marks: 100,
    allow_late: true,
    is_project: false,
    is_published: false,
});

function openForm(assignment = null) {
    editing.value = assignment;
    form.clearErrors();
    form.title = assignment?.title ?? '';
    form.brief = assignment?.brief ?? '';
    form.checklist = assignment?.checklist ? [...assignment.checklist] : [];
    form.course_module_id = assignment?.moduleId ?? null;
    form.batch_id = assignment?.batchId ?? null;
    form.due_at = assignment?.dueAt ?? '';
    form.max_marks = assignment?.maxMarks ?? 100;
    form.allow_late = assignment?.allowLate ?? true;
    form.is_project = assignment?.isProject ?? false;
    form.is_published = assignment?.isPublished ?? false;
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
        ? form.put(`${base.value}/assignments/${editing.value.id}`, done)
        : form.post(`${base.value}/assignments`, done);
}

function remove(assignment) {
    if (confirm(`Remove "${assignment.title}"?`)) {
        router.delete(`${base.value}/assignments/${assignment.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`${course.title} — assignments`" />

    <AppLayout
        title="Assignments"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: course.title, href: `/admin/courses/${course.id}/builder` },
            { label: 'Assignments' },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                title="Assignments and projects"
                :description="`What students hand in for ${course.title}. Marks from here carry half the final result.`"
            >
                <template #actions>
                    <UiButton :href="`${base}/builder`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Structure
                    </UiButton>
                    <UiButton :href="'/admin/marking'" variant="secondary" size="sm">Marking queue</UiButton>
                    <UiButton size="sm" @click="openForm()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Assignment
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!assignments.length"
                :icon="FileText"
                title="Nothing set yet"
                description="An assignment can be scoped to one batch, or set for everybody on the course."
            >
                <template #action>
                    <UiButton @click="openForm()">Set an assignment</UiButton>
                </template>
            </UiEmptyState>

            <UiCard v-for="assignment in assignments" :key="assignment.id" padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ assignment.title }}
                            <UiBadge v-if="assignment.isProject" tone="accent" size="sm">project</UiBadge>
                            <UiBadge v-if="!assignment.isPublished" tone="warning" size="sm">draft</UiBadge>
                            <UiBadge v-if="assignment.awaiting" tone="accent" size="sm">
                                <AlertCircle class="mr-1 h-3 w-3" />{{ assignment.awaiting }} to mark
                            </UiBadge>
                        </h2>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            {{ assignment.maxMarks }} marks
                            <span v-if="assignment.dueAtLabel"> · due {{ assignment.dueAtLabel }}</span>
                            <span v-if="!assignment.allowLate"> · no late submissions</span>
                            <span v-if="assignment.submissions"> · {{ assignment.submissions }} handed in</span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        <UiButton variant="ghost" size="xs" @click="openForm(assignment)">
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="remove(assignment)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>

        <UiModal
            :open="open"
            size="lg"
            :title="editing ? 'Edit assignment' : 'New assignment'"
            @close="open = false"
        >
            <form class="space-y-4" @submit.prevent="save">
                <UiFormField label="Title" required :error="form.errors.title">
                    <UiInput v-model="form.title" />
                </UiFormField>

                <UiFormField
                    label="Brief"
                    required
                    hint="What to build, what counts as done, and what to hand in."
                    :error="form.errors.brief"
                >
                    <UiTextarea v-model="form.brief" :rows="8" />
                </UiFormField>

                <UiFormField
                    label="Checklist"
                    hint="The points you will mark against. Students see this alongside the brief."
                    :error="form.errors.checklist"
                >
                    <UiRepeater v-model="form.checklist" placeholder="Add a point and press Enter" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Belongs to module" :error="form.errors.course_module_id">
                        <UiSelect v-model="form.course_module_id">
                            <option :value="null">Whole course</option>
                            <option v-for="module in modules" :key="module.value" :value="module.value">
                                {{ module.label }}
                            </option>
                        </UiSelect>
                    </UiFormField>

                    <UiFormField
                        label="Set for"
                        hint="One batch, or everybody on the course."
                        :error="form.errors.batch_id"
                    >
                        <UiSelect v-model="form.batch_id">
                            <option :value="null">Every batch</option>
                            <option v-for="batch in batches" :key="batch.value" :value="batch.value">
                                {{ batch.label }}
                            </option>
                        </UiSelect>
                    </UiFormField>

                    <UiFormField label="Due" :error="form.errors.due_at">
                        <UiInput v-model="form.due_at" type="datetime-local" />
                    </UiFormField>

                    <UiFormField label="Out of" required :error="form.errors.max_marks">
                        <UiInput v-model.number="form.max_marks" type="number" min="1" max="1000" />
                    </UiFormField>
                </div>

                <UiSwitch
                    v-model="form.allow_late"
                    label="Accept late submissions"
                    description="A late one is still marked, and flagged as late."
                />
                <UiSwitch
                    v-model="form.is_project"
                    label="This is the course project"
                    description="Projects appear on the internship project report."
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
