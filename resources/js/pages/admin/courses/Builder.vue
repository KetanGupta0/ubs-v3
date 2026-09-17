<script setup>
/**
 * The whole structure of one course on a single screen.
 *
 * Modules, their lessons and the files attached to them together, because an
 * unlock rule only makes sense next to the lesson it gates and the lesson it
 * waits for.
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    ArrowLeft, Plus, Pencil, Trash2, ChevronUp, ChevronDown, Lock, Eye,
    Paperclip, Download, Link2, PlayCircle, GripVertical,
} from 'lucide-vue-next';

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
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    modules: { type: Array, default: () => [] },
    allLessons: { type: Array, default: () => [] },
    quizzes: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/courses/${props.course.id}`);

/* ------------------------------------------------------------- modules */

const moduleOpen = ref(false);
const editingModule = ref(null);

const moduleForm = useForm({
    title: '',
    summary: '',
    unlock_after_days: null,
    is_published: true,
});

function openModule(module = null) {
    editingModule.value = module;
    moduleForm.clearErrors();
    moduleForm.title = module?.title ?? '';
    moduleForm.summary = module?.summary ?? '';
    moduleForm.unlock_after_days = module?.unlockAfterDays ?? null;
    moduleForm.is_published = module?.isPublished ?? true;
    moduleOpen.value = true;
}

function saveModule() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            moduleOpen.value = false;
            editingModule.value = null;
            moduleForm.reset();
        },
    };

    editingModule.value
        ? moduleForm.put(`${base.value}/modules/${editingModule.value.id}`, done)
        : moduleForm.post(`${base.value}/modules`, done);
}

function deleteModule(module) {
    if (confirm(`Remove "${module.title}" and every lesson in it?`)) {
        router.delete(`${base.value}/modules/${module.id}`, { preserveScroll: true });
    }
}

function moveModule(index, direction) {
    const order = props.modules.map((module) => module.id);
    const target = index + direction;

    if (target < 0 || target >= order.length) return;

    [order[index], order[target]] = [order[target], order[index]];

    router.post(`${base.value}/modules/reorder`, { order }, { preserveScroll: true });
}

/* ------------------------------------------------------------- lessons */

const lessonOpen = ref(false);
const editingLesson = ref(null);
const lessonModule = ref(null);

const lessonForm = useForm({
    title: '',
    summary: '',
    content: '',
    video_url: '',
    duration_minutes: null,
    unlock_after_days: null,
    unlock_at: '',
    prerequisite_lesson_id: null,
    requires_payment: false,
    required_quiz_id: null,
    min_quiz_score: null,
    is_preview: false,
    is_published: true,
});

function openLesson(module, lesson = null) {
    lessonModule.value = module;
    editingLesson.value = lesson;
    lessonForm.clearErrors();
    lessonForm.title = lesson?.title ?? '';
    lessonForm.summary = lesson?.summary ?? '';
    lessonForm.content = lesson?.content ?? '';
    lessonForm.video_url = lesson?.videoUrl ?? '';
    lessonForm.duration_minutes = lesson?.durationMinutes ?? null;
    lessonForm.unlock_after_days = lesson?.unlockAfterDays ?? null;
    lessonForm.unlock_at = lesson?.unlockAt ?? '';
    lessonForm.prerequisite_lesson_id = lesson?.prerequisiteLessonId ?? null;
    lessonForm.requires_payment = lesson?.requiresPayment ?? false;
    lessonForm.required_quiz_id = lesson?.requiredQuizId ?? null;
    lessonForm.min_quiz_score = lesson?.minQuizScore ?? null;
    lessonForm.is_preview = lesson?.isPreview ?? false;
    lessonForm.is_published = lesson?.isPublished ?? true;
    lessonOpen.value = true;
}

function saveLesson() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            lessonOpen.value = false;
            editingLesson.value = null;
            lessonForm.reset();
        },
    };

    editingLesson.value
        ? lessonForm.put(`${base.value}/lessons/${editingLesson.value.id}`, done)
        : lessonForm.post(`${base.value}/modules/${lessonModule.value.id}/lessons`, done);
}

function deleteLesson(lesson) {
    if (confirm(`Remove "${lesson.title}"?`)) {
        router.delete(`${base.value}/lessons/${lesson.id}`, { preserveScroll: true });
    }
}

function moveLesson(module, index, direction) {
    const order = module.lessons.map((lesson) => lesson.id);
    const target = index + direction;

    if (target < 0 || target >= order.length) return;

    [order[index], order[target]] = [order[target], order[index]];

    router.post(`${base.value}/modules/${module.id}/lessons/reorder`, { order }, { preserveScroll: true });
}

/** Lessons that may be a prerequisite: anything but the one being edited. */
const prerequisiteOptions = computed(() =>
    props.allLessons.filter((option) => option.value !== editingLesson.value?.id),
);

/* ----------------------------------------------------------- materials */

const materialOpen = ref(false);
const chosen = ref([]);

const materialForm = useForm({
    lesson_id: null,
    title: '',
    description: '',
    file: null,
    external_url: '',
    is_downloadable: true,
});

function openMaterial(lesson = null) {
    materialForm.clearErrors();
    materialForm.reset();
    chosen.value = [];
    materialForm.lesson_id = lesson?.id ?? null;
    materialOpen.value = true;
}

function saveMaterial() {
    materialForm.file = chosen.value[0] ?? null;

    materialForm.post(`${base.value}/materials`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            materialOpen.value = false;
            chosen.value = [];
            materialForm.reset();
        },
    });
}

function deleteMaterial(material) {
    if (confirm(`Remove "${material.title}"?`)) {
        router.delete(`${base.value}/materials/${material.id}`, { preserveScroll: true });
    }
}

/** A one line summary of what holds this lesson shut, for the list. */
function lockSummary(lesson) {
    const rules = [];

    if (lesson.requiresPayment) rules.push('fee');
    if (lesson.unlockAfterDays) rules.push(`day ${lesson.unlockAfterDays}`);
    if (lesson.unlockAt) rules.push(`from ${lesson.unlockAt}`);
    if (lesson.prerequisiteLessonId) rules.push('after another lesson');
    if (lesson.requiredQuizId) rules.push(`quiz ≥ ${lesson.minQuizScore ?? 0}%`);

    return rules.join(' · ');
}
</script>

<template>
    <Head :title="`${course.title} — structure`" />

    <AppLayout
        title="Course structure"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Courses', href: '/admin/courses' },
            { label: course.title },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                :title="course.title"
                description="Modules hold lessons, lessons hold content and files. A student sees them in this order."
            >
                <template #actions>
                    <UiButton :href="`/admin/courses/${course.id}/edit`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Course details
                    </UiButton>
                    <UiButton :href="`${base}/quizzes`" variant="secondary" size="sm">Quizzes</UiButton>
                    <UiButton :href="`${base}/assignments`" variant="secondary" size="sm">Assignments</UiButton>
                    <UiButton size="sm" @click="openModule()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Module
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                <UiBadge size="sm">{{ course.type }}</UiBadge>
                <span>{{ modules.length }} modules · {{ course.lessonCount }} lessons</span>
                <UiBadge v-if="!course.isPublished" tone="warning" size="sm">course not published</UiBadge>
            </div>

            <UiEmptyState
                v-if="!modules.length"
                :icon="PlayCircle"
                title="No modules yet"
                description="Start with a module — a week, a phase, or a theme — then put lessons inside it."
            >
                <template #action>
                    <UiButton @click="openModule()">Add the first module</UiButton>
                </template>
            </UiEmptyState>

            <!-- ------------------------------------------------- modules -->
            <UiCard v-for="(module, moduleIndex) in modules" :key="module.id" padding="p-0">
                <template #header>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <h2 class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                <GripVertical class="h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" />
                                {{ module.title }}
                                <UiBadge v-if="!module.isPublished" tone="warning" size="sm">draft</UiBadge>
                                <UiBadge v-if="module.unlockAfterDays" size="sm">
                                    <Lock class="mr-1 h-3 w-3" />opens day {{ module.unlockAfterDays }}
                                </UiBadge>
                            </h2>
                            <p v-if="module.summary" class="mt-1 text-xs" style="color: var(--text-muted)">
                                {{ module.summary }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <UiButton
                                variant="ghost"
                                size="xs"
                                aria-label="Move module up"
                                :disabled="moduleIndex === 0"
                                @click="moveModule(moduleIndex, -1)"
                            >
                                <ChevronUp class="h-3.5 w-3.5" />
                            </UiButton>
                            <UiButton
                                variant="ghost"
                                size="xs"
                                aria-label="Move module down"
                                :disabled="moduleIndex === modules.length - 1"
                                @click="moveModule(moduleIndex, 1)"
                            >
                                <ChevronDown class="h-3.5 w-3.5" />
                            </UiButton>
                            <UiButton variant="ghost" size="xs" @click="openModule(module)">
                                <Pencil class="h-3.5 w-3.5" />
                            </UiButton>
                            <UiButton variant="ghost" size="xs" @click="deleteModule(module)">
                                <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                            </UiButton>
                        </div>
                    </div>
                </template>

                <p v-if="!module.lessons.length" class="py-2 text-sm" style="color: var(--text-muted)">
                    No lessons in this module yet.
                </p>

                <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="(lesson, lessonIndex) in module.lessons" :key="lesson.id" class="py-3 first:pt-0">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                    {{ lessonIndex + 1 }}. {{ lesson.title }}
                                    <UiBadge v-if="lesson.isPreview" tone="accent" size="sm">
                                        <Eye class="mr-1 h-3 w-3" />preview
                                    </UiBadge>
                                    <UiBadge v-if="!lesson.isPublished" tone="warning" size="sm">draft</UiBadge>
                                </p>
                                <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                    <span v-if="lesson.durationMinutes">{{ lesson.durationMinutes }} min</span>
                                    <span v-if="lesson.videoUrl"> · video</span>
                                    <span v-if="lockSummary(lesson)"> · locked by {{ lockSummary(lesson) }}</span>
                                </p>

                                <ul v-if="lesson.materials.length" class="mt-2 space-y-1">
                                    <li
                                        v-for="material in lesson.materials"
                                        :key="material.id"
                                        class="flex items-center gap-2 text-xs"
                                        style="color: var(--text-muted)"
                                    >
                                        <component :is="material.isLink ? Link2 : Paperclip" class="h-3 w-3 shrink-0" />
                                        <span class="min-w-0 truncate">{{ material.title }}</span>
                                        <span v-if="material.size">· {{ material.size }}</span>
                                        <a
                                            v-if="!material.isLink"
                                            :href="`${base}/materials/${material.id}`"
                                            class="inline-flex items-center gap-1 hover:underline"
                                        >
                                            <Download class="h-3 w-3" />
                                        </a>
                                        <button
                                            type="button"
                                            class="hover:underline"
                                            style="color: var(--color-danger-500)"
                                            @click="deleteMaterial(material)"
                                        >
                                            remove
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <UiButton
                                    variant="ghost"
                                    size="xs"
                                    aria-label="Move lesson up"
                                    :disabled="lessonIndex === 0"
                                    @click="moveLesson(module, lessonIndex, -1)"
                                >
                                    <ChevronUp class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton
                                    variant="ghost"
                                    size="xs"
                                    aria-label="Move lesson down"
                                    :disabled="lessonIndex === module.lessons.length - 1"
                                    @click="moveLesson(module, lessonIndex, 1)"
                                >
                                    <ChevronDown class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="openMaterial(lesson)">
                                    <Paperclip class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="openLesson(module, lesson)">
                                    <Pencil class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="deleteLesson(lesson)">
                                    <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                                </UiButton>
                            </div>
                        </div>
                    </li>
                </ul>

                <template #footer>
                    <div class="flex flex-wrap gap-2">
                        <UiButton variant="secondary" size="sm" @click="openLesson(module)">
                            <template #leading><Plus class="h-3.5 w-3.5" /></template>
                            Lesson
                        </UiButton>
                        <UiButton variant="ghost" size="sm" @click="openMaterial()">
                            <template #leading><Paperclip class="h-3.5 w-3.5" /></template>
                            Course level material
                        </UiButton>
                    </div>
                </template>
            </UiCard>
        </div>

        <!-- ------------------------------------------------ module form -->
        <UiModal
            :open="moduleOpen"
            :title="editingModule ? 'Edit module' : 'New module'"
            @close="moduleOpen = false"
        >
            <form class="space-y-4" @submit.prevent="saveModule">
                <UiFormField label="Title" required :error="moduleForm.errors.title">
                    <UiInput v-model="moduleForm.title" placeholder="Week 1 — the shape of a web app" />
                </UiFormField>

                <UiFormField label="Summary" :error="moduleForm.errors.summary">
                    <UiTextarea v-model="moduleForm.summary" :rows="2" />
                </UiFormField>

                <UiFormField
                    label="Opens this many days after the batch starts"
                    hint="Leave blank to open immediately."
                    :error="moduleForm.errors.unlock_after_days"
                >
                    <UiInput v-model.number="moduleForm.unlock_after_days" type="number" min="0" />
                </UiFormField>

                <UiSwitch v-model="moduleForm.is_published" label="Visible to students" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="moduleOpen = false">Cancel</UiButton>
                <UiButton :loading="moduleForm.processing" @click="saveModule">Save</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------ lesson form -->
        <UiModal
            :open="lessonOpen"
            size="lg"
            :title="editingLesson ? 'Edit lesson' : 'New lesson'"
            @close="lessonOpen = false"
        >
            <form class="space-y-4" @submit.prevent="saveLesson">
                <UiFormField label="Title" required :error="lessonForm.errors.title">
                    <UiInput v-model="lessonForm.title" />
                </UiFormField>

                <UiFormField label="Summary" :error="lessonForm.errors.summary">
                    <UiTextarea v-model="lessonForm.summary" :rows="2" />
                </UiFormField>

                <UiFormField
                    label="Notes"
                    hint="What the student reads alongside the recording."
                    :error="lessonForm.errors.content"
                >
                    <UiTextarea v-model="lessonForm.content" :rows="6" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Video link" :error="lessonForm.errors.video_url">
                        <UiInput v-model="lessonForm.video_url" placeholder="https://" />
                    </UiFormField>

                    <UiFormField label="Length in minutes" :error="lessonForm.errors.duration_minutes">
                        <UiInput v-model.number="lessonForm.duration_minutes" type="number" min="1" />
                    </UiFormField>
                </div>

                <div class="rounded-[var(--radius-control)] p-4" style="background: var(--surface-sunken)">
                    <p class="mb-3 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        <Lock class="h-3.5 w-3.5" />
                        What holds this lesson shut
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Days after the batch starts" :error="lessonForm.errors.unlock_after_days">
                            <UiInput v-model.number="lessonForm.unlock_after_days" type="number" min="0" />
                        </UiFormField>

                        <UiFormField label="Or a fixed date" :error="lessonForm.errors.unlock_at">
                            <UiDateInput v-model="lessonForm.unlock_at" />
                        </UiFormField>

                        <UiFormField label="After this lesson is finished" :error="lessonForm.errors.prerequisite_lesson_id">
                            <UiSelect v-model="lessonForm.prerequisite_lesson_id">
                                <option :value="null">No prerequisite</option>
                                <option v-for="option in prerequisiteOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </UiSelect>
                        </UiFormField>

                        <UiFormField label="After passing this quiz" :error="lessonForm.errors.required_quiz_id">
                            <UiSelect v-model="lessonForm.required_quiz_id">
                                <option :value="null">No quiz gate</option>
                                <option v-for="option in quizzes" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </UiSelect>
                        </UiFormField>

                        <UiFormField
                            v-if="lessonForm.required_quiz_id"
                            label="Minimum quiz score"
                            :error="lessonForm.errors.min_quiz_score"
                        >
                            <UiInput v-model.number="lessonForm.min_quiz_score" type="number" min="1" max="100" />
                        </UiFormField>
                    </div>

                    <div class="mt-4 space-y-3">
                        <UiSwitch
                            v-model="lessonForm.requires_payment"
                            label="Needs the fee settled"
                            description="An unpaid student sees the title and the summary, not the content."
                        />
                        <UiSwitch
                            v-model="lessonForm.is_preview"
                            label="Free preview"
                            description="Open even before payment. Overrides the rule above."
                        />
                    </div>
                </div>

                <UiSwitch v-model="lessonForm.is_published" label="Visible to students" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="lessonOpen = false">Cancel</UiButton>
                <UiButton :loading="lessonForm.processing" @click="saveLesson">Save</UiButton>
            </template>
        </UiModal>

        <!-- ---------------------------------------------- material form -->
        <UiModal :open="materialOpen" title="Add material" @close="materialOpen = false">
            <form class="space-y-4" @submit.prevent="saveMaterial">
                <UiFormField label="Title" required :error="materialForm.errors.title">
                    <UiInput v-model="materialForm.title" />
                </UiFormField>

                <UiFormField label="Description" :error="materialForm.errors.description">
                    <UiTextarea v-model="materialForm.description" :rows="2" />
                </UiFormField>

                <UiFormField
                    label="File"
                    hint="Up to 50 MB. For anything larger, host it and paste the link below."
                    :error="materialForm.errors.file"
                >
                    <UiFileDrop v-model="chosen" :max-size="50" />
                </UiFormField>

                <UiFormField label="Or a link" :error="materialForm.errors.external_url">
                    <UiInput v-model="materialForm.external_url" placeholder="https://" />
                </UiFormField>

                <UiSwitch v-model="materialForm.is_downloadable" label="Students may download it" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="materialOpen = false">Cancel</UiButton>
                <UiButton :loading="materialForm.processing" @click="saveMaterial">Add</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
