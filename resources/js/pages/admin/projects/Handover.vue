<script setup>
/**
 * Writing a project's handover pack (CL15). Save as often as needed; the
 * client sees the pack as it was last published, and acknowledges each
 * revision. Where access was shared, never what it is (X6).
 */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ShieldAlert } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    handover: { type: Object, required: true },
    state: { type: Object, required: true },
});

const form = useForm({ ...props.handover, licences: [...props.handover.licences], videos: [...props.handover.videos], publish: false });

function save(publish) {
    if (publish && !window.confirm(`Publish revision ${props.state.revision + 1}? The client is asked to acknowledge it.`)) return;
    form.publish = publish;
    form.put(`/admin/projects/${props.project.id}/handover`, { preserveScroll: true });
}

const listError = (prefix) => Object.entries(form.errors).find(([key]) => key.startsWith(prefix))?.[1];
</script>

<template>
    <Head :title="`Handover · ${project.name}`" />

    <AppLayout :title="`Handover · ${project.name}`" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Projects', href: '/admin/projects' }, { label: project.code ?? project.name, href: `/admin/projects/${project.id}` }, { label: 'Handover' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="`Handover pack · ${project.name}`" description="What the client comes back to a year later: where the code is, how to deploy it, what is licensed, training and the warranty.">
                <template #actions>
                    <UiButton :href="`/admin/projects/${project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge v-if="!state.revision" size="sm">Not published yet</UiBadge>
                    <template v-else>
                        <UiBadge :tone="state.acknowledged ? 'success' : 'warning'" size="sm" dot>
                            {{ state.acknowledged ? `Revision ${state.revision} acknowledged` : `Revision ${state.revision} waiting for the client` }}
                        </UiBadge>
                        <span style="color: var(--text-muted)">Published {{ state.publishedAt }}<template v-if="state.acknowledgedBy"> · last acknowledged by {{ state.acknowledgedBy }}, {{ state.acknowledgedAt }} (revision {{ state.acknowledgedRevision }})</template></span>
                    </template>
                </div>
                <p v-if="form.errors.handover" class="mt-3 text-sm font-medium text-danger-500" role="alert">{{ form.errors.handover }}</p>
            </UiCard>

            <UiCard>
                <form class="space-y-4" @submit.prevent="save(false)">
                    <UiFormField label="Where the code is" hint="The repository, and who was given access to it." :error="form.errors.repository">
                        <UiTextarea v-model="form.repository" :rows="3" />
                    </UiFormField>
                    <UiFormField label="Deploying it" hint="Markdown. Steps, commands, the order things go in." :error="form.errors.deployment_guide">
                        <UiTextarea v-model="form.deployment_guide" :rows="8" />
                    </UiFormField>
                    <UiFormField label="Environments" hint="Markdown. What runs where, settings that matter, scheduled jobs." :error="form.errors.environment_notes">
                        <UiTextarea v-model="form.environment_notes" :rows="6" />
                    </UiFormField>
                    <div class="rounded-xl p-4" style="background: var(--surface-sunken)">
                        <p class="flex items-center gap-2 text-sm font-medium"><ShieldAlert class="h-4 w-4" style="color: var(--color-warn-600)" aria-hidden="true" /> Access</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">Say where access was shared and with whom, such as "hosting, registrar and repository owner access transferred to Rahul on 12 Nov". Never a password, key or token: one is refused on save.</p>
                        <UiFormField class="mt-3" label="Where access was shared" :error="form.errors.credentials_note">
                            <UiTextarea v-model="form.credentials_note" :rows="3" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Licences" :error="listError('licences')">
                        <UiObjectRepeater v-model="form.licences" :fields="[{ key: 'name', label: 'What', placeholder: 'Map tiles, 50,000 loads a month' }, { key: 'detail', label: 'Detail', placeholder: 'Account in the client\'s name, renews yearly' }]" title-key="name" add-label="Add a licence" :max="30" />
                    </UiFormField>
                    <UiFormField label="Training videos" :error="listError('videos')">
                        <UiObjectRepeater v-model="form.videos" :fields="[{ key: 'title', label: 'Title' }, { key: 'url', label: 'Link (https)' }]" title-key="title" add-label="Add a video" :max="30" />
                    </UiFormField>
                    <UiFormField label="Warranty ends" :error="form.errors.warranty_ends_on">
                        <UiInput v-model="form.warranty_ends_on" type="date" class="max-w-xs" />
                    </UiFormField>
                    <div class="flex flex-wrap justify-end gap-2">
                        <UiButton type="submit" variant="secondary" :loading="form.processing && !form.publish">Save</UiButton>
                        <UiButton type="button" :loading="form.processing && form.publish" @click="save(true)">Publish to the client</UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
