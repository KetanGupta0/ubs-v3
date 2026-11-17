<script setup>
/** One piece of staging feedback, for our side to move along (CL13). */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FeedbackThread from '@/components/Projects/FeedbackThread.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    item: { type: Object, required: true },
    moves: { type: Array, default: () => [] },
});

const base = `/admin/feedback/${props.item.id}`;
const tones = { new: 'warning', acknowledged: 'brand', in_progress: 'brand', fixed: 'success', closed: 'neutral', declined: 'neutral' };

const moveForm = useForm({ to: props.moves[0]?.value ?? '', note: '' });
const commentForm = useForm({ body: '' });

function move() {
    moveForm.post(`${base}/move`, { preserveScroll: true, onSuccess: () => { moveForm.note = ''; } });
}

function comment() {
    commentForm.post(`${base}/comment`, { preserveScroll: true, onSuccess: () => commentForm.reset() });
}
</script>

<template>
    <Head :title="item.title" />

    <AppLayout :title="item.title" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Staging feedback', href: '/admin/feedback' }, { label: item.title }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="item.title" :description="`${item.kind} · ${item.client} · ${item.project.name}${item.milestone ? ` · holds up ${item.milestone}` : ''}`">
                <template #actions>
                    <UiButton href="/admin/feedback" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Feedback
                    </UiButton>
                    <UiButton v-if="item.project.stagingUrl" :href="item.project.stagingUrl" :inertia="false" target="_blank" variant="secondary" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        Staging
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[item.status] ?? 'neutral'" dot>{{ item.statusLabel }}</UiBadge>
                    <span style="color: var(--text-muted)">Reported {{ item.reportedAt }}<template v-if="item.reportedBy"> by {{ item.reportedBy }}</template></span>
                    <Link :href="`/admin/projects/${item.project.id}`" class="hover:underline" style="color: var(--text-muted)">{{ item.project.name }}</Link>
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

            <UiCard v-if="moves.length">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Move it on</h2>
                <form class="mt-4 space-y-3" @submit.prevent="move">
                    <UiFormField label="Now" :error="moveForm.errors.to">
                        <UiSelect v-model="moveForm.to" :options="moves" />
                    </UiFormField>
                    <UiFormField label="Note for them" :hint="moveForm.to === 'declined' ? 'Required: say why it is not changing.' : 'Optional. They are told when it is fixed or not changing, or when you write a note.'" :error="moveForm.errors.note">
                        <UiTextarea v-model="moveForm.note" :rows="3" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" size="sm" :loading="moveForm.processing">Update</UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard>
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">So far</h2>
                <div class="mt-4"><FeedbackThread :events="item.events" /></div>
                <form v-if="['new', 'acknowledged', 'in_progress', 'fixed'].includes(item.status)" class="mt-5 space-y-3 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="comment">
                    <UiFormField label="Add a comment" hint="The person who reported it is told." :error="commentForm.errors.body">
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
