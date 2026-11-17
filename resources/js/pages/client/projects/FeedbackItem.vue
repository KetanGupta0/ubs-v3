<script setup>
/** One piece of staging feedback (CL13): what was reported, where it stands, and checking the fix. */
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, ExternalLink, RotateCcw } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FeedbackThread from '@/components/Projects/FeedbackThread.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    item: { type: Object, required: true },
    canConfirm: { type: Boolean, default: false },
    canComment: { type: Boolean, default: false },
});

const base = `/client/projects/${props.project.id}/feedback/${props.item.id}`;
const tones = { new: 'warning', acknowledged: 'brand', in_progress: 'brand', fixed: 'success', closed: 'neutral', declined: 'neutral' };

const stillHappening = ref(false);
const confirmForm = useForm({ works: true, note: '' });
const commentForm = useForm({ body: '' });

function works() {
    confirmForm.works = true;
    confirmForm.note = '';
    confirmForm.post(`${base}/confirm`, { preserveScroll: true });
}

function reopen() {
    confirmForm.works = false;
    confirmForm.post(`${base}/confirm`, { preserveScroll: true, onSuccess: () => { stillHappening.value = false; confirmForm.reset(); } });
}

function comment() {
    commentForm.post(`${base}/comment`, { preserveScroll: true, onSuccess: () => commentForm.reset() });
}
</script>

<template>
    <Head :title="item.title" />

    <AppLayout
        :title="item.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: project.name, href: `/client/projects/${project.id}` },
            { label: 'Feedback', href: `/client/projects/${project.id}/feedback` },
            { label: item.title },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="item.title" :description="`${item.kind}${item.milestone ? ` · holds up ${item.milestone}` : ''}`">
                <template #actions>
                    <UiButton :href="`/client/projects/${project.id}/feedback`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Feedback
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[item.status] ?? 'neutral'" dot>{{ item.statusLabel }}</UiBadge>
                    <span style="color: var(--text-muted)">Reported {{ item.reportedAt }}<template v-if="item.reportedBy"> by {{ item.reportedBy }}</template></span>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm">{{ item.details }}</p>
                <a v-if="item.pageUrl" :href="item.pageUrl" target="_blank" rel="noopener noreferrer" class="mt-3 inline-flex items-center gap-1 break-all text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                    {{ item.pageUrl }} <ExternalLink class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
                <div v-if="item.screenshots.length" class="mt-4 grid gap-3 sm:grid-cols-3">
                    <a v-for="(src, index) in item.screenshots" :key="src" :href="src" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border" style="border-color: var(--border-subtle)">
                        <img :src="src" :alt="`Screenshot ${index + 1} of ${item.title}`" class="h-40 w-full object-cover object-top" loading="lazy">
                    </a>
                </div>
            </UiCard>

            <UiCard v-if="canConfirm">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Is it fixed?</h2>
                <p class="mt-1 text-sm" style="color: var(--text-muted)">Try it on staging, then tell us.</p>
                <div v-if="!stillHappening" class="mt-4 flex flex-wrap gap-2">
                    <UiButton :loading="confirmForm.processing && confirmForm.works" @click="works">
                        <template #leading><CheckCircle2 class="h-4 w-4" /></template>
                        Yes, it works
                    </UiButton>
                    <UiButton variant="secondary" @click="stillHappening = true">
                        <template #leading><RotateCcw class="h-4 w-4" /></template>
                        It still happens
                    </UiButton>
                </div>
                <form v-else class="mt-4 space-y-3" @submit.prevent="reopen">
                    <UiFormField label="What still happens" required :error="confirmForm.errors.note">
                        <UiTextarea v-model="confirmForm.note" :rows="4" />
                    </UiFormField>
                    <div class="flex justify-end gap-2">
                        <UiButton variant="ghost" type="button" @click="stillHappening = false">Cancel</UiButton>
                        <UiButton type="submit" :loading="confirmForm.processing" :disabled="!confirmForm.note.trim()">Send it back</UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard>
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">So far</h2>
                <div class="mt-4"><FeedbackThread :events="item.events" /></div>
                <form v-if="canComment" class="mt-5 space-y-3 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="comment">
                    <UiFormField label="Add a comment" :error="commentForm.errors.body">
                        <UiTextarea v-model="commentForm.body" :rows="3" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" size="sm" :loading="commentForm.processing" :disabled="!commentForm.body.trim()">Add</UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
