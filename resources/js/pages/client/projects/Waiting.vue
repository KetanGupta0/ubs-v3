<script setup>
/**
 * Something we need from the client (CL10), and their reply.
 *
 * Words, files or both. Files go into the organisation's documents marked as
 * sent by them. Access is the one kind that warns: a password typed into a
 * reply is a password stored in somebody else's database.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import WaitingHistory from '@/components/Projects/WaitingHistory.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    item: { type: Object, required: true },
});

const form = useForm({ body: '', files: [] });

function send() {
    form.post(`/client/projects/${props.project.id}/waiting/${props.item.id}/answer`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
}

const tones = { open: 'warning', answered: 'brand', closed: 'success', cancelled: 'neutral' };

function fileError() {
    return Object.entries(form.errors).find(([key]) => key.startsWith('files'))?.[1];
}
</script>

<template>
    <Head :title="item.title" />

    <AppLayout
        :title="item.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: project.code, href: `/client/projects/${project.id}` },
            { label: 'Waiting on you' },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="item.title" :description="`${item.kindLabel} · ${project.name}`">
                <template #actions>
                    <UiButton :href="`/client/projects/${project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[item.status] ?? 'neutral'" dot>{{ item.statusLabel }}</UiBadge>
                    <span v-if="item.dueOn" :style="{ color: item.overdue ? 'var(--color-warn-600)' : 'var(--text-muted)' }">
                        {{ item.overdue ? 'Was wanted by' : 'Wanted by' }} {{ item.dueOn }}
                    </span>
                    <span v-if="item.blocksWork && item.status === 'open'" style="color: var(--text-muted)">· work on this part waits for it</span>
                </div>
                <p v-if="item.details" class="mt-3 whitespace-pre-line text-sm">{{ item.details }}</p>
            </UiCard>

            <UiCard v-if="item.status === 'open'">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Your reply</h2>

                <p v-if="item.kind === 'access'" class="mt-3 flex items-start gap-2 rounded-xl p-3 text-sm" style="background: var(--surface-sunken)">
                    <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-warn-600)" aria-hidden="true" />
                    <span>Please do not type passwords or keys here. Invite our team account to the system, or tell us how you would like to share access safely, and we will arrange it.</span>
                </p>

                <form class="mt-4 space-y-4" @submit.prevent="send">
                    <UiFormField label="Message" :error="form.errors.body">
                        <UiTextarea v-model="form.body" :rows="5" maxlength="5000" />
                    </UiFormField>
                    <UiFormField label="Files" hint="Up to five, 20 MB each. For anything larger, share a link in your message." :error="fileError()">
                        <UiFileDrop v-model="form.files" multiple :max-size="20" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing" :disabled="!form.body.trim() && !form.files.length">
                            <template #leading><Send class="h-4 w-4" /></template>
                            Send
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard>
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">So far</h2>
                <p v-if="item.waited && item.waited !== 'no time'" class="mt-1 text-xs" style="color: var(--text-muted)">Waiting on your side in all: {{ item.waited }}.</p>
                <div class="mt-4">
                    <WaitingHistory :events="item.events" :file-href="(id) => `/client/documents/${id}/download`" />
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
