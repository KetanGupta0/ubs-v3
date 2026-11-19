<script setup>
/**
 * One meeting and its minutes (CL11). Acknowledging records that the
 * minutes are right; if they are not, saying what is wrong gets a corrected
 * version, which is acknowledged afresh. Earlier versions stay readable.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CheckCircle2, ExternalLink, MapPin, MessageSquareWarning } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    meeting: { type: Object, required: true },
    minutes: { type: Object, default: null },
    earlier: { type: Array, default: () => [] },
    canAnswer: { type: Boolean, default: false },
});

const base = `/client/meetings/${props.meeting.id}`;
const questioning = ref(false);
const disputeForm = useForm({ note: '' });

function acknowledge() {
    router.post(`${base}/acknowledge`, {}, { preserveScroll: true });
}

function question() {
    disputeForm.post(`${base}/dispute`, { preserveScroll: true, onSuccess: () => { questioning.value = false; disputeForm.reset(); } });
}
</script>

<template>
    <Head :title="meeting.title" />

    <AppLayout :title="meeting.title" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Meetings', href: '/client/meetings' }, { label: meeting.title }]">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="meeting.title" :description="meeting.project ? meeting.project.name : null">
                <template #actions>
                    <UiButton href="/client/meetings" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Meetings
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <p class="text-sm font-medium">{{ meeting.when }} <span class="font-normal" style="color: var(--text-muted)">(India time)</span></p>
                <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge v-if="meeting.status === 'cancelled'" size="sm">Cancelled</UiBadge>
                    <UiButton v-if="meeting.link && meeting.status === 'scheduled'" :href="meeting.link" :inertia="false" target="_blank" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        Join
                    </UiButton>
                    <span v-if="meeting.location" class="inline-flex items-center gap-1" style="color: var(--text-muted)"><MapPin class="h-3.5 w-3.5" aria-hidden="true" />{{ meeting.location }}</span>
                </div>
                <p v-if="meeting.agenda" class="mt-3 whitespace-pre-line text-sm">{{ meeting.agenda }}</p>
                <p v-if="meeting.cancelReason" class="mt-3 text-sm" style="color: var(--text-muted)">{{ meeting.cancelReason }}</p>
            </UiCard>

            <UiCard v-if="minutes">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-base font-semibold" style="color: var(--text-strong)">Minutes<template v-if="minutes.version > 1"> · version {{ minutes.version }}</template></h2>
                    <UiBadge v-if="minutes.acknowledgedAt" tone="success" size="sm" dot>Acknowledged by {{ minutes.acknowledgedBy }}</UiBadge>
                    <UiBadge v-else-if="minutes.disputedAt" tone="warning" size="sm" dot>You asked for a correction</UiBadge>
                </div>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">Present: {{ minutes.attendees.join(', ') }}</p>
                <div class="mt-4"><MarkdownHtml :html="minutes.html" /></div>

                <div v-if="minutes.clientActions.length || minutes.teamActions.length" class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div v-if="minutes.clientActions.length">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">You agreed to</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="(action, index) in minutes.clientActions" :key="index" class="flex gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />{{ action.title }}<template v-if="action.due"> · by {{ action.due }}</template></li>
                        </ul>
                    </div>
                    <div v-if="minutes.teamActions.length">
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">We agreed to</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            <li v-for="(action, index) in minutes.teamActions" :key="index" class="flex gap-2"><CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />{{ action.text }}<template v-if="action.owner"> · {{ action.owner }}</template></li>
                        </ul>
                    </div>
                </div>

                <p v-if="minutes.disputedAt" class="mt-4 flex gap-2 rounded-xl p-3 text-sm" style="background: var(--surface-sunken)">
                    <MessageSquareWarning class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-warning)" aria-hidden="true" />
                    <span>“{{ minutes.disputeNote }}” · {{ minutes.disputedBy }}, {{ minutes.disputedAt }}. We will send a corrected version.</span>
                </p>

                <div v-if="canAnswer" class="mt-5 flex flex-wrap gap-2 border-t pt-4" style="border-color: var(--border-subtle)">
                    <UiButton @click="acknowledge">
                        <template #leading><CheckCircle2 class="h-4 w-4" /></template>
                        These minutes are right
                    </UiButton>
                    <UiButton variant="secondary" @click="questioning = true">
                        <template #leading><MessageSquareWarning class="h-4 w-4" /></template>
                        Something is not right
                    </UiButton>
                </div>
            </UiCard>

            <UiCard v-else-if="meeting.status === 'held' || meeting.past">
                <p class="text-sm" style="color: var(--text-muted)">The minutes will appear here once we have written them up.</p>
            </UiCard>

            <details v-if="earlier.length" class="text-sm">
                <summary class="cursor-pointer select-none text-xs" style="color: var(--text-muted)">Earlier versions ({{ earlier.length }})</summary>
                <UiCard v-for="version in earlier" :key="version.id" class="mt-3">
                    <p class="text-xs" style="color: var(--text-muted)">Version {{ version.version }}, published {{ version.publishedAt }}<template v-if="version.disputeNote"> · you said: “{{ version.disputeNote }}”</template></p>
                    <div class="mt-3"><MarkdownHtml :html="version.html" /></div>
                </UiCard>
            </details>
        </div>

        <UiModal :open="questioning" title="What is not right?" description="Say what the minutes got wrong or left out. We will send a corrected version." @close="questioning = false">
            <form id="dispute-form" @submit.prevent="question">
                <UiFormField label="What needs correcting" required :error="disputeForm.errors.note">
                    <UiTextarea v-model="disputeForm.note" :rows="5" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="questioning = false">Cancel</UiButton>
                <UiButton type="submit" form="dispute-form" :loading="disputeForm.processing" :disabled="disputeForm.note.trim().length < 10">Send</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
