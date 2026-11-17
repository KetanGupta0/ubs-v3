<script setup>
/**
 * Feedback on the staging preview (CL13): everything reported on this
 * project, and a way to report more, with screenshots and the milestone it
 * holds up. Feedback sent over a messaging app gets lost; here it is kept.
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Bug, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    project: { type: Object, required: true },
    table: { type: Object, required: true },
    milestones: { type: Array, default: () => [] },
    kinds: { type: Array, default: () => [] },
    canReport: { type: Boolean, default: false },
});

const tones = { new: 'warning', acknowledged: 'brand', in_progress: 'brand', fixed: 'success', closed: 'neutral', declined: 'neutral' };
const blank = { kind: 'bug', title: '', details: '', page_url: '', milestone_id: '', screenshots: [] };

const reporting = ref(false);
const form = useForm({ ...blank, screenshots: [] });

function open() {
    blankForm(form, blank);
    reporting.value = true;
}

function report() {
    form.transform((data) => ({ ...data, milestone_id: data.milestone_id || null })).post(`/client/projects/${props.project.id}/feedback`, { forceFormData: true });
}

const screenshotError = () => Object.entries(form.errors).find(([key]) => key.startsWith('screenshots'))?.[1];
</script>

<template>
    <Head :title="`Feedback · ${project.name}`" />

    <AppLayout
        title="Feedback on staging"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: project.code ?? project.name, href: `/client/projects/${project.id}` },
            { label: 'Feedback' },
        ]"
    >
        <div class="mx-auto max-w-6xl">
            <PageHeader title="Feedback on staging" :description="`${project.name}. Report what is broken or should change on the preview; we tell you as it moves, and you confirm the fix.`">
                <template #actions>
                    <UiButton :href="`/client/projects/${project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                    <UiButton v-if="project.stagingUrl" :href="project.stagingUrl" :inertia="false" target="_blank" variant="secondary" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        Staging
                    </UiButton>
                    <UiButton v-if="canReport" size="sm" @click="open">
                        <template #leading><Bug class="h-3.5 w-3.5" /></template>
                        Report something
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search feedback…" empty-title="Nothing reported yet" :empty-description="canReport ? 'Report the first thing with Report something.' : 'Your technical people or an owner can report what they find on staging.'">
                <template #cell:title="{ row }">
                    <Link :href="`/client/projects/${project.id}/feedback/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/client/projects/${project.id}/feedback/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.kind }} · {{ row.updated_at }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>

        <UiModal :open="reporting" title="Report something" description="What happened, and what you expected. A screenshot saves a lot of back and forth." @close="reporting = false">
            <form id="feedback-form" class="space-y-4" @submit.prevent="report">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="What kind" required :error="form.errors.kind">
                        <UiSelect v-model="form.kind" :options="kinds" />
                    </UiFormField>
                    <UiFormField label="Holds up" :error="form.errors.milestone_id">
                        <UiSelect v-model="form.milestone_id" :options="[{ value: '', label: 'No milestone in particular' }, ...milestones]" />
                    </UiFormField>
                </div>
                <UiFormField label="In a line" required :error="form.errors.title">
                    <UiInput v-model="form.title" maxlength="160" placeholder="Scanning a returned parcel shows an error" />
                </UiFormField>
                <UiFormField label="What happened, and what you expected" required :error="form.errors.details">
                    <UiTextarea v-model="form.details" :rows="5" />
                </UiFormField>
                <UiFormField label="Page address" hint="Optional: where on staging." :error="form.errors.page_url">
                    <UiInput v-model="form.page_url" type="url" placeholder="https://" />
                </UiFormField>
                <UiFormField label="Screenshots" hint="Up to three, JPG, PNG or WebP." :error="screenshotError()">
                    <UiFileDrop v-model="form.screenshots" multiple accept="image/png,image/jpeg,image/webp" :max-size="8" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="reporting = false">Cancel</UiButton>
                <UiButton type="submit" form="feedback-form" :loading="form.processing" :disabled="!form.title.trim() || !form.details.trim()">Report</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
