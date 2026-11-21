<script setup>
/**
 * One meeting (CL11): its details, the minutes being written, and every
 * published version with the client's answer to it. A published version
 * never changes; a correction is the next version.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Ban, CheckCircle2, ExternalLink, MapPin, MessageSquareWarning, NotebookPen, Pencil, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    meeting: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    draft: { type: Object, required: true },
    canWriteMinutes: { type: Boolean, default: false },
});

const base = `/admin/meetings/${props.meeting.id}`;
const tones = { scheduled: 'brand', held: 'success', cancelled: 'neutral' };

const latest = computed(() => props.versions[0] ?? null);
const writing = ref(props.draft.exists || (!latest.value && props.canWriteMinutes));

const form = useForm({
    attendees: [...props.draft.attendees],
    body: props.draft.body,
    client_actions: props.draft.client_actions.map((action) => ({ ...action })),
    team_actions: props.draft.team_actions.map((action) => ({ ...action })),
    publish: false,
});

function save(publish) {
    if (publish && !window.confirm(`Publish version ${props.draft.version}? It cannot be changed afterwards, and the client is asked to acknowledge it.`)) return;

    form.publish = publish;
    form.put(`${base}/minutes`, { preserveScroll: true, onSuccess: () => { if (publish) writing.value = false; } });
}

function cancelMeeting() {
    const reason = window.prompt('Cancel this meeting? Add a line for the client if you like.', '');
    if (reason === null) return;
    router.post(`${base}/cancel`, { reason }, { preserveScroll: true });
}

const actionError = (prefix) => Object.entries(form.errors).find(([key]) => key.startsWith(prefix))?.[1];
</script>

<template>
    <Head :title="meeting.title" />

    <AppLayout :title="meeting.title" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Meetings', href: '/admin/meetings' }, { label: meeting.title }]">
        <div class="space-y-5">
            <PageHeader :title="meeting.title" :description="`${meeting.client}${meeting.project ? ` · ${meeting.project.name}` : ''}`">
                <template #actions>
                    <UiButton href="/admin/meetings" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Meetings
                    </UiButton>
                    <template v-if="meeting.status === 'scheduled'">
                        <UiButton :href="`${base}/edit`" variant="secondary" size="sm">
                            <template #leading><Pencil class="h-3.5 w-3.5" /></template>
                            Change
                        </UiButton>
                        <UiButton variant="ghost" size="sm" @click="cancelMeeting">
                            <template #leading><Ban class="h-3.5 w-3.5" /></template>
                            Cancel
                        </UiButton>
                    </template>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[meeting.status] ?? 'neutral'" dot>{{ meeting.statusLabel }}</UiBadge>
                    <span>{{ meeting.when }}</span>
                    <a v-if="meeting.link" :href="meeting.link" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-medium text-brand-600 hover:underline dark:text-brand-400">
                        Join link <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                    </a>
                    <span v-if="meeting.location" class="inline-flex items-center gap-1" style="color: var(--text-muted)"><MapPin class="h-3.5 w-3.5" aria-hidden="true" />{{ meeting.location }}</span>
                    <Link v-if="meeting.project" :href="`/admin/projects/${meeting.project.id}`" class="hover:underline" style="color: var(--text-muted)">{{ meeting.project.name }}</Link>
                </div>
                <p v-if="meeting.agenda" class="mt-3 whitespace-pre-line text-sm">{{ meeting.agenda }}</p>
                <p v-if="meeting.cancelReason" class="mt-3 text-sm" style="color: var(--text-muted)">Cancelled: {{ meeting.cancelReason }}</p>
            </UiCard>

            <!-- -------------------------------------------- client's answer -->
            <UiCard v-if="latest?.disputedAt" class="border-l-4" style="border-left-color: var(--color-warn-500)">
                <p class="flex items-center gap-2 text-sm font-semibold"><MessageSquareWarning class="h-4 w-4" style="color: var(--text-warning)" aria-hidden="true" /> The client says version {{ latest.version }} is not right</p>
                <p class="mt-2 whitespace-pre-line text-sm">“{{ latest.disputeNote }}”</p>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ latest.disputedBy }}, {{ latest.disputedAt }}. Write the next version with the correction.</p>
            </UiCard>

            <!-- ------------------------------------------- writing minutes -->
            <UiCard v-if="canWriteMinutes && !writing">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm" style="color: var(--text-muted)">{{ latest ? `Version ${latest.version} is published.` : 'No minutes yet.' }}</p>
                    <UiButton size="sm" variant="secondary" @click="writing = true">
                        <template #leading><NotebookPen class="h-3.5 w-3.5" /></template>
                        {{ latest ? `Write version ${draft.version}` : 'Write the minutes' }}
                    </UiButton>
                </div>
            </UiCard>

            <UiCard v-if="writing">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">{{ draft.version > 1 ? `Version ${draft.version} of the minutes` : 'Minutes' }}</h2>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">The client sees nothing until you publish. Once published, this version cannot change.</p>

                <form class="mt-4 space-y-4" @submit.prevent="save(false)">
                    <UiFormField label="Who was there" required :error="form.errors.attendees ?? actionError('attendees.')">
                        <UiRepeater v-model="form.attendees" :max="30" placeholder="Type a name and press Enter" />
                    </UiFormField>
                    <UiFormField label="What was discussed and decided" hint="Markdown: ## headings, - lists, **bold**." required :error="form.errors.body">
                        <UiTextarea v-model="form.body" :rows="10" />
                    </UiFormField>
                    <UiFormField label="What the client agreed to do" :hint="meeting.project ? 'Each becomes an item waiting on them in the project when you publish.' : 'Link the meeting to a project to give the client actions.'" :error="actionError('client_actions')">
                        <UiObjectRepeater
                            v-model="form.client_actions"
                            :fields="[{ key: 'title', label: 'Action', placeholder: 'Send the warehouse floor plans' }, { key: 'due_on', label: 'By', type: 'date' }]"
                            title-key="title"
                            add-label="Add a client action"
                            :max="20"
                        />
                    </UiFormField>
                    <UiFormField label="What we agreed to do" :error="actionError('team_actions')">
                        <UiObjectRepeater
                            v-model="form.team_actions"
                            :fields="[{ key: 'text', label: 'Action', placeholder: 'Share the picking prototype' }, { key: 'owner', label: 'Who on our side' }]"
                            title-key="text"
                            add-label="Add one of ours"
                            :max="20"
                        />
                    </UiFormField>
                    <div class="flex flex-wrap justify-end gap-2">
                        <UiButton type="submit" variant="secondary" :loading="form.processing && !form.publish">Save the draft</UiButton>
                        <UiButton type="button" :loading="form.processing && form.publish" :disabled="!meeting.past" :title="meeting.past ? '' : 'Publish once the meeting has started'" @click="save(true)">
                            <template #leading><Send class="h-4 w-4" /></template>
                            Publish
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <!-- ------------------------------------------ published versions -->
            <UiCard v-for="version in versions" :key="version.id">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold" style="color: var(--text-strong)">Version {{ version.version }}</h2>
                    <UiBadge v-if="version.acknowledgedAt" tone="success" size="sm" dot>Acknowledged by {{ version.acknowledgedBy }}, {{ version.acknowledgedAt }}</UiBadge>
                    <UiBadge v-else-if="version.disputedAt" tone="warning" size="sm" dot>Questioned</UiBadge>
                    <UiBadge v-else size="sm" dot>Waiting for the client</UiBadge>
                </div>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">Published {{ version.publishedAt }}<template v-if="version.publishedBy"> by {{ version.publishedBy }}</template> · present: {{ version.attendees.join(', ') }}</p>
                <div class="mt-4"><MarkdownHtml :html="version.html" /></div>
                <div v-if="version.clientActions.length || version.teamActions.length" class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div v-if="version.clientActions.length">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">The client</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="(action, index) in version.clientActions" :key="index" class="flex gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />{{ action.title }}<template v-if="action.due"> · by {{ action.due }}</template></li>
                        </ul>
                    </div>
                    <div v-if="version.teamActions.length">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Us</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="(action, index) in version.teamActions" :key="index" class="flex gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />{{ action.text }}<template v-if="action.owner"> · {{ action.owner }}</template></li>
                        </ul>
                    </div>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
