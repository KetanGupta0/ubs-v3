<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, Upload, Download, CheckCircle2, AlertTriangle, ExternalLink, Send,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    assignment: { type: Object, required: true },
    submission: { type: Object, default: null },
});

const chosen = ref([]);

const form = useForm({
    notes: props.submission?.notes ?? '',
    repository_url: props.submission?.repositoryUrl ?? '',
    demo_url: props.submission?.demoUrl ?? '',
    files: [],
});

function submit() {
    form.files = chosen.value;

    form.post(`/student/assignments/${props.assignment.id}`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (chosen.value = []),
    });
}
</script>

<template>
    <Head :title="assignment.title" />

    <AppLayout
        :title="assignment.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: 'Assignments', href: '/student/assignments' },
            { label: assignment.title },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="assignment.title" :description="assignment.course">
                <template #actions>
                    <UiButton href="/student/assignments" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <UiBadge v-if="assignment.isProject" tone="accent" size="sm">project</UiBadge>
                <UiBadge size="sm">{{ assignment.maxMarks }} marks</UiBadge>
                <UiBadge v-if="assignment.dueAt" :tone="assignment.overdue ? 'warning' : 'neutral'" size="sm">
                    due {{ assignment.dueAt }}
                </UiBadge>
                <span v-if="assignment.overdue && assignment.allowLate" class="text-xs" style="color: var(--text-muted)">
                    Late submissions are still accepted, and marked as late.
                </span>
            </div>

            <!-- ---------------------------------------------- the brief -->
            <UiCard>
                <template #header><h2 class="text-base font-semibold">The brief</h2></template>

                <div class="whitespace-pre-line text-sm leading-relaxed" style="color: var(--text-base)">
                    {{ assignment.brief }}
                </div>

                <div v-if="assignment.checklist.length" class="mt-5">
                    <p class="mb-2 text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">
                        What we are looking for
                    </p>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="(item, index) in assignment.checklist" :key="index" class="flex gap-2">
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-signal-500)" />
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </div>
            </UiCard>

            <!-- ------------------------------------------- the feedback -->
            <UiCard v-if="submission?.feedback">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">Feedback</h2>
                        <UiBadge v-if="submission.marks !== null" tone="success" size="sm">
                            {{ submission.marks }} / {{ assignment.maxMarks }}
                            <span v-if="submission.percent"> · {{ submission.percent }}%</span>
                        </UiBadge>
                        <UiBadge v-else tone="warning" size="sm">sent back for another go</UiBadge>
                    </div>
                </template>

                <p class="whitespace-pre-line text-sm">{{ submission.feedback }}</p>
                <p v-if="submission.evaluatedBy" class="mt-2 text-xs" style="color: var(--text-muted)">
                    {{ submission.evaluatedBy }} · {{ submission.evaluatedAt }}
                </p>
            </UiCard>

            <!-- ------------------------------------------ your submission -->
            <UiCard v-if="submission?.submittedAt">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">What you handed in</h2>
                        <UiBadge v-if="submission.late" tone="warning" size="sm">late</UiBadge>
                    </div>
                </template>

                <p class="text-xs" style="color: var(--text-muted)">{{ submission.submittedAt }}</p>

                <p v-if="submission.notes" class="mt-3 whitespace-pre-line text-sm">{{ submission.notes }}</p>

                <div v-if="submission.repositoryUrl || submission.demoUrl" class="mt-3 flex flex-wrap gap-2">
                    <UiButton v-if="submission.repositoryUrl" :href="submission.repositoryUrl" :inertia="false"
                              target="_blank" variant="ghost" size="xs">
                        <template #leading><ExternalLink class="h-3 w-3" /></template>
                        Repository
                    </UiButton>
                    <UiButton v-if="submission.demoUrl" :href="submission.demoUrl" :inertia="false"
                              target="_blank" variant="ghost" size="xs">
                        <template #leading><ExternalLink class="h-3 w-3" /></template>
                        Demonstration
                    </UiButton>
                </div>

                <ul v-if="submission.files.length" class="mt-3 space-y-1.5">
                    <li v-for="file in submission.files" :key="file.index" class="flex items-center gap-2 text-sm">
                        <Download class="h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" />
                        <a
                            :href="`/student/assignments/${assignment.id}/files/${file.index}`"
                            class="hover:underline"
                        >{{ file.name }}</a>
                    </li>
                </ul>
            </UiCard>

            <!-- ---------------------------------------------- submitting -->
            <UiCard v-if="assignment.acceptsSubmissions">
                <template #header>
                    <h2 class="text-base font-semibold">
                        {{ submission?.submittedAt ? 'Submit again' : 'Hand it in' }}
                    </h2>
                </template>

                <p
                    v-if="assignment.overdue"
                    class="mb-4 flex items-start gap-2 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                    style="background: var(--surface-sunken)"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                    <span style="color: var(--text-muted)">
                        Past the deadline, so this will be recorded as late. Still worth doing.
                    </span>
                </p>

                <p
                    v-if="submission?.marks !== null && submission?.marks !== undefined"
                    class="mb-4 text-xs"
                    style="color: var(--text-muted)"
                >
                    Submitting again clears the mark you already have, because a mark that belongs to work you have
                    since changed is worse than no mark.
                </p>

                <form class="space-y-4" @submit.prevent="submit">
                    <UiFormField label="Notes for your trainer" :error="form.errors.notes">
                        <UiTextarea v-model="form.notes" :rows="4" placeholder="Anything they should know before marking…" />
                    </UiFormField>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Repository" :error="form.errors.repository_url" hint="GitHub, GitLab, wherever it lives.">
                            <UiInput v-model="form.repository_url" type="url" placeholder="https://github.com/..." />
                        </UiFormField>

                        <UiFormField label="Live demonstration" :error="form.errors.demo_url">
                            <UiInput v-model="form.demo_url" type="url" />
                        </UiFormField>
                    </div>

                    <UiFormField label="Files" :error="form.errors.files" hint="Up to five files, 10 MB each.">
                        <UiFileDrop v-model="chosen" multiple :max-size="10" />
                    </UiFormField>

                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing">
                            <template #leading><Send class="h-4 w-4" /></template>
                            {{ submission?.submittedAt ? 'Submit again' : 'Hand it in' }}
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard v-else>
                <p class="text-sm" style="color: var(--text-muted)">
                    This one is closed for submissions.
                </p>
            </UiCard>
        </div>
    </AppLayout>
</template>
