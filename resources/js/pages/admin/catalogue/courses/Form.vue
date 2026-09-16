<script setup>
/**
 * One form for courses, programmes and internships.
 *
 * The internship specific fields appear only when the type is internship,
 * rather than being permanently present and mostly empty.
 */
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink, Trash2, Info } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';

const props = defineProps({
    course: { type: Object, default: null },
    defaultType: { type: String, default: 'course' },
    types: { type: Array, default: () => [] },
    levels: { type: Array, default: () => [] },
    accents: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.course));

const form = useForm({
    title: props.course?.title ?? '',
    slug: props.course?.slug ?? '',
    type: props.course?.type ?? props.defaultType,
    tagline: props.course?.tagline ?? '',
    summary: props.course?.summary ?? '',
    description: props.course?.description ?? '',
    level: props.course?.level ?? 'beginner',
    duration_weeks: props.course?.duration_weeks ?? '',
    duration_months: props.course?.duration_months ?? '',
    hours_per_week: props.course?.hours_per_week ?? '',
    price: props.course?.price ?? 0,
    sale_price: props.course?.sale_price ?? '',
    visibility: props.course?.visibility ?? 'public',
    mode: props.course?.mode ?? 'remote',
    project_focus: props.course?.project_focus ?? '',
    audience: props.course?.audience ?? [],
    prerequisites: props.course?.prerequisites ?? [],
    tools: props.course?.tools ?? [],
    outcomes: props.course?.outcomes ?? [],
    documents_provided: props.course?.documents_provided ?? [],
    syllabus: props.course?.syllabus ?? [],
    accent: props.course?.accent ?? 'brand',
    is_featured: props.course?.is_featured ?? false,
    is_published: props.course?.is_published ?? false,
    sort_order: props.course?.sort_order ?? 0,
});

const isInternship = computed(() => form.type === 'internship');

function submit() {
    isEdit.value
        ? form.put(`/admin/courses/${props.course.id}`, { preserveScroll: true })
        : form.post('/admin/courses');
}

function destroy() {
    if (confirm('Delete this? Unpublishing is usually what you want instead.')) {
        router.delete(`/admin/courses/${props.course.id}`);
    }
}

/** The four documents a college expects, so nobody has to remember them. */
function addStandardDocuments() {
    form.documents_provided = [
        { title: 'Offer letter', body: 'Issued before you start, on company letterhead, so you can submit it to your department.' },
        { title: 'Completion certificate', body: 'Issued at the end with a public verification link, so your college can confirm it online.' },
        { title: 'Project report', body: 'A structured report of what you built, formatted the way most universities expect.' },
        { title: 'Mentor evaluation', body: 'A signed evaluation from the engineer who reviewed your work, with marks.' },
    ];
}
</script>

<template>
    <Head :title="isEdit ? course.title : 'New offering'" />

    <AppLayout
        :title="isEdit ? course.title : 'New offering'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Courses', href: '/admin/courses' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="isEdit ? course.title : 'New offering'">
                <template #actions>
                    <UiButton href="/admin/courses" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton v-if="isEdit && course.publicUrl" :href="course.publicUrl" :inertia="false" variant="ghost" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        View live
                    </UiButton>
                    <UiButton type="submit" size="sm" :loading="form.processing">{{ isEdit ? 'Save' : 'Create' }}</UiButton>
                </template>
            </PageHeader>

            <FormSection title="The basics">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Type" required :error="form.errors.type">
                        <template #default="field">
                            <UiSelect :id="field.id" v-model="form.type" :options="types.map((t) => ({ value: t, label: t }))" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Level" required :error="form.errors.level">
                        <template #default="field">
                            <UiSelect :id="field.id" v-model="form.level" :options="levels.map((l) => ({ value: l, label: l }))" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Title" required :error="form.errors.title">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.title" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField label="URL slug" hint="Leave empty to build it from the title." :error="form.errors.slug">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.slug" class="font-mono" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Tagline" required :error="form.errors.tagline">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.tagline" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField label="Summary" required :error="form.errors.summary">
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.summary" :rows="3" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField
                    label="The longer explanation"
                    hint="Leave a blank line between paragraphs."
                    :error="form.errors.description"
                >
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.description" :rows="7" :invalid="field.invalid" />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="Shape and price">
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Duration (weeks)" :error="form.errors.duration_weeks">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.duration_weeks" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField
                        label="Duration (months)"
                        hint="Set this to show months instead."
                        :error="form.errors.duration_months"
                    >
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.duration_months" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Hours a week" :error="form.errors.hours_per_week">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.hours_per_week" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Fee (₹)" required hint="Zero shows as On request." :error="form.errors.price">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.price" type="number" min="0" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField label="Sale price (₹)" :error="form.errors.sale_price">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.sale_price" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Mode" required :error="form.errors.mode">
                        <template #default="field">
                            <UiSelect
                                :id="field.id"
                                v-model="form.mode"
                                :options="[
                                    { value: 'remote', label: 'Remote' },
                                    { value: 'hybrid', label: 'Hybrid' },
                                    { value: 'onsite', label: 'On site' },
                                ]"
                            />
                        </template>
                    </UiFormField>
                </div>
            </FormSection>

            <!-- ----------------------------------------------- internship only -->
            <FormSection
                v-if="isInternship"
                title="Internship paperwork"
                description="For a student meeting a curriculum requirement this is often the deciding factor, so it is stated as prominently as the syllabus."
            >
                <UiFormField
                    label="What they build"
                    hint="One line, shown on the card and the page."
                    :error="form.errors.project_focus"
                >
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.project_focus"
                            placeholder="A multi page web application with a database behind it"
                            :invalid="field.invalid"
                        />
                    </template>
                </UiFormField>

                <UiFormField label="Documents produced" :error="form.errors.documents_provided">
                    <template #default>
                        <div class="space-y-3">
                            <button
                                v-if="!form.documents_provided.length"
                                type="button"
                                class="flex w-full items-start gap-3 rounded-xl border border-dashed p-4 text-left transition hover:bg-[var(--surface-sunken)]"
                                style="border-color: var(--border-strong)"
                                @click="addStandardDocuments"
                            >
                                <Info class="mt-0.5 h-4 w-4 shrink-0 text-brand-500" aria-hidden="true" />
                                <span>
                                    <span class="block text-sm font-medium" style="color: var(--text-strong)">
                                        Use the standard four
                                    </span>
                                    <span class="block text-xs" style="color: var(--text-muted)">
                                        Offer letter, completion certificate, project report and mentor evaluation.
                                    </span>
                                </span>
                            </button>

                            <UiObjectRepeater
                                v-model="form.documents_provided"
                                title-key="title"
                                add-label="Add a document"
                                :fields="[
                                    { key: 'title', label: 'Document', type: 'text', placeholder: 'Completion certificate' },
                                    { key: 'body', label: 'What it is', type: 'textarea', rows: 2 },
                                ]"
                            />
                        </div>
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="Who it is for and what they get">
                <UiFormField label="Who it is for" :error="form.errors.audience">
                    <template #default>
                        <UiRepeater v-model="form.audience" placeholder="Second and third year engineering students" />
                    </template>
                </UiFormField>

                <UiFormField label="What they need first" :error="form.errors.prerequisites">
                    <template #default>
                        <UiRepeater v-model="form.prerequisites" placeholder="A laptop and a stable internet connection" />
                    </template>
                </UiFormField>

                <UiFormField label="Tools they will use" :error="form.errors.tools">
                    <template #default>
                        <UiRepeater v-model="form.tools" chips placeholder="Laravel" />
                    </template>
                </UiFormField>

                <UiFormField label="What they will be able to do" :error="form.errors.outcomes">
                    <template #default>
                        <UiRepeater v-model="form.outcomes" placeholder="Build and deploy a full application on your own" />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="Syllabus" description="Each module expands on the public page.">
                <UiObjectRepeater
                    v-model="form.syllabus"
                    title-key="module"
                    add-label="Add a module"
                    :fields="[
                        { key: 'module', label: 'Module', type: 'text', placeholder: 'Foundations' },
                        { key: 'weeks', label: 'Weeks', type: 'text', placeholder: '1–3' },
                        { key: 'topics', label: 'Topics', type: 'list', placeholder: 'How the web actually works' },
                    ]"
                />
            </FormSection>

            <FormSection title="Presentation and visibility">
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Visibility" required :error="form.errors.visibility">
                        <template #default="field">
                            <UiSelect
                                :id="field.id"
                                v-model="form.visibility"
                                :options="[
                                    { value: 'public', label: 'On the public site' },
                                    { value: 'lms_only', label: 'Inside the LMS only' },
                                ]"
                            />
                        </template>
                    </UiFormField>

                    <UiFormField label="Accent colour" :error="form.errors.accent">
                        <template #default="field">
                            <UiSelect :id="field.id" v-model="form.accent" :options="accents.map((a) => ({ value: a, label: a }))" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Order" hint="Lower comes first." :error="form.errors.sort_order">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.sort_order" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="space-y-3 border-t pt-4" style="border-color: var(--border-subtle)">
                    <UiSwitch v-model="form.is_published" label="Published" />
                    <UiSwitch v-model="form.is_featured" label="Featured" description="Appears on the home page." />
                </div>

                <p
                    v-if="form.visibility === 'lms_only'"
                    class="rounded-xl px-3.5 py-2.5 text-sm"
                    style="background: color-mix(in oklab, var(--color-warn-500) 10%, transparent); color: var(--text-base)"
                >
                    Marked as inside the LMS only. It will not be listed publicly, its URL returns a
                    404, and it stays out of the sitemap.
                </p>
            </FormSection>

            <div class="flex items-center justify-between gap-2">
                <UiButton v-if="isEdit" type="button" variant="ghost" size="sm" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                    Delete
                </UiButton>
                <span v-else />

                <span class="flex gap-2">
                    <UiButton href="/admin/courses" variant="secondary">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">{{ isEdit ? 'Save changes' : 'Create' }}</UiButton>
                </span>
            </div>
        </form>
    </AppLayout>
</template>
