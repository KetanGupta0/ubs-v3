<script setup>
/**
 * Marking one submission.
 *
 * Feedback is required by the server, not just encouraged here: a mark with
 * nothing written next to it teaches the student nothing they can use.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, Github, ExternalLink, RotateCcw, Check } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    submission: { type: Object, required: true },
    assignment: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});

const form = useForm({
    marks: props.submission.marks ?? null,
    feedback: props.submission.feedback ?? '',
    status: 'evaluated',
});

function evaluate(status) {
    form.status = status;
    form.post(`/admin/marking/submissions/${props.submission.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Marking — ${submission.student}`" />

    <AppLayout
        title="Marking"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Marking', href: '/admin/marking' },
            { label: submission.student },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader
                :title="assignment.title"
                :description="`${submission.student} · ${assignment.course}`"
            >
                <template #actions>
                    <UiButton href="/admin/marking" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Queue
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                <UiBadge size="sm">out of {{ assignment.maxMarks }}</UiBadge>
                <UiBadge v-if="submission.late" tone="warning" size="sm">handed in late</UiBadge>
                <span>Submitted {{ submission.submittedAt }}</span>
                <span v-if="submission.evaluatedBy">· last marked by {{ submission.evaluatedBy }}</span>
            </div>

            <!-- ------------------------------------------- what was set -->
            <UiCard>
                <template #header><h2 class="text-base font-semibold">The brief</h2></template>

                <p class="whitespace-pre-line text-sm" style="color: var(--text-muted)">{{ assignment.brief }}</p>

                <ul v-if="assignment.checklist.length" class="mt-4 space-y-1.5">
                    <li
                        v-for="point in assignment.checklist"
                        :key="point"
                        class="flex items-start gap-2 text-sm"
                    >
                        <Check class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" />
                        {{ point }}
                    </li>
                </ul>
            </UiCard>

            <!-- ----------------------------------------- what came back -->
            <UiCard>
                <template #header><h2 class="text-base font-semibold">What was handed in</h2></template>

                <p v-if="submission.notes" class="whitespace-pre-line text-sm" style="color: var(--text-muted)">
                    {{ submission.notes }}
                </p>
                <p v-else class="text-sm" style="color: var(--text-muted)">No notes were written.</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <UiButton
                        v-if="submission.repositoryUrl"
                        :href="submission.repositoryUrl"
                        :inertia="false"
                        target="_blank"
                        rel="noopener"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><Github class="h-3.5 w-3.5" /></template>
                        Repository
                    </UiButton>

                    <UiButton
                        v-if="submission.demoUrl"
                        :href="submission.demoUrl"
                        :inertia="false"
                        target="_blank"
                        rel="noopener"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        Demo
                    </UiButton>

                    <UiButton
                        v-for="file in submission.files"
                        :key="file.index"
                        :href="`/admin/marking/submissions/${submission.id}/files/${file.index}`"
                        :inertia="false"
                        variant="ghost"
                        size="sm"
                    >
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        {{ file.name }}
                    </UiButton>
                </div>
            </UiCard>

            <!-- ------------------------------------------ earlier versions -->
            <UiCard v-if="history.length">
                <template #header>
                    <h2 class="text-base font-semibold">Earlier versions</h2>
                </template>
                <ol class="space-y-4">
                    <li v-for="item in history" :key="item.version" class="border-l-2 pl-3" style="border-color: var(--border-subtle)">
                        <p class="text-sm font-medium">
                            Version {{ item.version }}
                            <span class="font-normal" style="color: var(--text-muted)">· {{ item.submittedAt }}</span>
                            <UiBadge v-if="item.late" tone="warning" size="sm" class="ml-1">late</UiBadge>
                        </p>
                        <p v-if="item.notes" class="mt-1 whitespace-pre-line text-sm">{{ item.notes }}</p>
                        <div v-if="item.files.length" class="mt-1.5 flex flex-wrap gap-2">
                            <UiButton
                                v-for="file in item.files"
                                :key="file.index"
                                :href="`/admin/marking/submissions/${submission.id}/files/${file.index}?version=${item.version}`"
                                :inertia="false"
                                variant="ghost"
                                size="xs"
                            >
                                <template #leading><Download class="h-3 w-3" /></template>
                                {{ file.name }}
                            </UiButton>
                        </div>
                        <div v-if="item.feedback" class="mt-2 rounded-lg px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                            <p class="text-xs font-medium" style="color: var(--text-muted)">
                                {{ item.marks !== null ? `${item.marks} / ${assignment.maxMarks}` : 'Sent back' }}<template v-if="item.evaluatedBy"> · {{ item.evaluatedBy }}</template>
                            </p>
                            <p class="mt-1 whitespace-pre-line">{{ item.feedback }}</p>
                        </div>
                    </li>
                </ol>
            </UiCard>

            <!-- --------------------------------------------- the marking -->
            <UiCard>
                <template #header><h2 class="text-base font-semibold">Your mark</h2></template>

                <form class="space-y-4" @submit.prevent="evaluate('evaluated')">
                    <UiFormField
                        :label="`Marks out of ${assignment.maxMarks}`"
                        required
                        :error="form.errors.marks"
                    >
                        <UiInput v-model.number="form.marks" type="number" min="0" :max="assignment.maxMarks" />
                    </UiFormField>

                    <UiFormField
                        label="Feedback"
                        required
                        hint="What was good, what to fix, and what to read next. The student sees this."
                        :error="form.errors.feedback"
                    >
                        <UiTextarea v-model="form.feedback" :rows="7" />
                    </UiFormField>
                </form>

                <template #footer>
                    <div class="flex flex-wrap justify-end gap-2">
                        <UiButton variant="secondary" :loading="form.processing" @click="evaluate('returned')">
                            <template #leading><RotateCcw class="h-3.5 w-3.5" /></template>
                            Send back for another go
                        </UiButton>
                        <UiButton :loading="form.processing" @click="evaluate('evaluated')">
                            <template #leading><Check class="h-3.5 w-3.5" /></template>
                            Save the mark
                        </UiButton>
                    </div>
                </template>
            </UiCard>

            <p class="px-1 text-xs" style="color: var(--text-muted)">
                Sending it back clears the mark, so the student is not left with a number attached to work they
                are about to change.
            </p>
        </div>
    </AppLayout>
</template>
