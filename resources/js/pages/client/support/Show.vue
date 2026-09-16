<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Send, CheckCircle2, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
});

const reply = useForm({ body: '' });
const closing = ref(false);
const closeForm = useForm({ satisfaction: 5 });

function send() {
    reply.post(`/client/tickets/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

function close() {
    closeForm.post(`/client/tickets/${props.ticket.id}/close`, {
        preserveScroll: true,
        onSuccess: () => (closing.value = false),
    });
}
</script>

<template>
    <Head :title="ticket.subject" />

    <AppLayout
        :title="ticket.subject"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Support', href: '/client/support' },
            { label: ticket.reference },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="ticket.subject" :description="ticket.reference">
                <template #actions>
                    <UiButton href="/client/support" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton v-if="ticket.canClose" variant="secondary" size="sm" @click="closing = true">
                        <template #leading><CheckCircle2 class="h-3.5 w-3.5" /></template>
                        Close this ticket
                    </UiButton>
                </template>
            </PageHeader>

            <!-- ------------------------------------------------------- SLA -->
            <UiCard padding="p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <UiBadge :tone="ticket.open ? 'brand' : 'neutral'" size="sm" dot>{{ ticket.statusLabel }}</UiBadge>
                    <UiBadge size="sm">{{ ticket.priority }}</UiBadge>
                    <UiBadge v-if="ticket.breached" tone="warning" size="sm">
                        <AlertTriangle class="mr-1 h-3 w-3" />{{ ticket.sla }}
                    </UiBadge>
                    <span v-if="ticket.project" class="text-xs" style="color: var(--text-muted)">{{ ticket.project }}</span>
                </div>

                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Raised</dt>
                        <dd>{{ ticket.at }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">First reply promised</dt>
                        <dd>{{ ticket.responseDueAt || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">We first replied</dt>
                        <dd>{{ ticket.firstResponseAt || 'Not yet' }}</dd>
                    </div>
                </dl>
            </UiCard>

            <!-- -------------------------------------------------- the thread -->
            <UiCard>
                <article class="border-b pb-4" style="border-color: var(--border-subtle)">
                    <p class="text-xs font-medium" style="color: var(--text-muted)">You wrote</p>
                    <p class="mt-1.5 whitespace-pre-line text-sm">{{ ticket.body }}</p>
                </article>

                <ol class="mt-4 space-y-4">
                    <li v-for="message in messages" :key="message.id" class="flex gap-3">
                        <span
                            class="mt-1 h-2 w-2 shrink-0 rounded-full"
                            :style="{ background: message.fromUs ? 'var(--color-brand-500)' : 'var(--border-strong)' }"
                        />
                        <div
                            class="min-w-0 flex-1 rounded-[var(--radius-card)] p-3"
                            :style="{ background: message.fromUs ? 'var(--surface-sunken)' : 'transparent' }"
                        >
                            <p class="text-xs font-medium" style="color: var(--text-muted)">
                                {{ message.author }} · {{ message.at }}
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm">{{ message.body }}</p>
                        </div>
                    </li>
                </ol>

                <form v-if="ticket.canReply" class="mt-5 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="send">
                    <UiFormField label="Add a reply" :error="reply.errors.body">
                        <UiTextarea v-model="reply.body" :rows="4" placeholder="Anything else that would help us…" />
                    </UiFormField>

                    <div class="mt-3 flex justify-end">
                        <UiButton type="submit" size="sm" :loading="reply.processing" :disabled="!reply.body.trim()">
                            <template #leading><Send class="h-3.5 w-3.5" /></template>
                            Send
                        </UiButton>
                    </div>
                </form>

                <p v-else class="mt-5 border-t pt-4 text-sm" style="border-color: var(--border-subtle); color: var(--text-muted)">
                    This ticket is closed. Raise a new one if it comes back.
                </p>
            </UiCard>
        </div>

        <UiModal
            :open="closing"
            title="Close this ticket?"
            description="Only do this if the problem is actually sorted. You can always raise a new one."
            @close="closing = false"
        >
            <form id="close-form" @submit.prevent="close">
                <UiFormField label="How did we do?" hint="Optional, and it genuinely helps.">
                    <div class="flex gap-2">
                        <button
                            v-for="score in [1, 2, 3, 4, 5]"
                            :key="score"
                            type="button"
                            class="h-10 w-10 rounded-lg border text-sm font-semibold transition"
                            :style="{
                                borderColor: closeForm.satisfaction === score ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                                background: closeForm.satisfaction === score ? 'var(--color-brand-500)' : 'transparent',
                                color: closeForm.satisfaction === score ? '#fff' : 'var(--text-base)',
                            }"
                            @click="closeForm.satisfaction = score"
                        >
                            {{ score }}
                        </button>
                    </div>
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="closing = false">Cancel</UiButton>
                <UiButton type="submit" form="close-form" :loading="closeForm.processing">Close the ticket</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
