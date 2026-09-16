<script setup>
/**
 * Answering one ticket.
 *
 * An internal note does not stop the response clock, because the clock measures
 * what the client experienced, not what we wrote down.
 */
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Send, AlertTriangle, EyeOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    ticket: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
});

const reply = useForm({ body: '', is_internal: false, status: null });

function send() {
    reply.post(`/admin/tickets/${props.ticket.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => reply.reset(),
    });
}

function patch(field, value) {
    router.put(`/admin/tickets/${props.ticket.id}`, { [field]: value }, { preserveScroll: true });
}

const priorityTones = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' };
</script>

<template>
    <Head :title="ticket.subject" />

    <AppLayout
        :title="ticket.subject"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Tickets', href: '/admin/tickets' },
            { label: ticket.reference },
        ]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="ticket.subject" :description="ticket.reference">
                <template #actions>
                    <UiButton href="/admin/tickets" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All tickets
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- ------------------------------------------- the thread -->
                <div class="lg:col-span-2 space-y-5">
                    <UiCard>
                        <article class="border-b pb-4" style="border-color: var(--border-subtle)">
                            <p class="text-xs font-medium" style="color: var(--text-muted)">
                                {{ ticket.client.name }} · {{ ticket.raisedAt }}
                            </p>
                            <p class="mt-1.5 whitespace-pre-line text-sm">{{ ticket.body }}</p>
                        </article>

                        <ol class="mt-4 space-y-4">
                            <li v-for="message in messages" :key="message.id" class="flex gap-3">
                                <span
                                    class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                                    :style="{
                                        background: message.internal
                                            ? 'var(--color-warn-500)'
                                            : message.fromUs
                                                ? 'var(--color-brand-500)'
                                                : 'var(--border-strong)',
                                    }"
                                />
                                <div
                                    class="min-w-0 flex-1 rounded-[var(--radius-card)] p-3"
                                    :style="{
                                        background: message.internal
                                            ? 'var(--surface-sunken)'
                                            : message.fromUs ? 'var(--surface-sunken)' : 'transparent',
                                    }"
                                >
                                    <p class="flex flex-wrap items-center gap-2 text-xs font-medium" style="color: var(--text-muted)">
                                        {{ message.author }} · {{ message.at }}
                                        <UiBadge v-if="message.internal" tone="warning" size="sm">
                                            <EyeOff class="mr-1 h-3 w-3" />internal
                                        </UiBadge>
                                    </p>
                                    <p class="mt-1 whitespace-pre-line text-sm">{{ message.body }}</p>
                                </div>
                            </li>
                        </ol>

                        <form class="mt-5 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="send">
                            <UiFormField label="Reply" :error="reply.errors.body">
                                <UiTextarea v-model="reply.body" :rows="5" />
                            </UiFormField>

                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <UiSwitch
                                    v-model="reply.is_internal"
                                    label="Internal note"
                                    description="The client never sees this, and it does not stop the response clock."
                                />

                                <div class="flex items-center gap-2">
                                    <div class="w-44">
                                        <UiSelect
                                            v-model="reply.status"
                                            :options="[
                                                { value: '', label: 'Leave status alone' },
                                                ...statuses.map((s) => ({ value: s, label: s.replace('_', ' ') })),
                                            ]"
                                            size="sm"
                                            aria-label="Set status with this reply"
                                        />
                                    </div>

                                    <UiButton type="submit" size="sm" :loading="reply.processing" :disabled="!reply.body.trim()">
                                        <template #leading><Send class="h-3.5 w-3.5" /></template>
                                        Send
                                    </UiButton>
                                </div>
                            </div>
                        </form>
                    </UiCard>
                </div>

                <!-- ----------------------------------------------- the SLA -->
                <div class="space-y-5">
                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Where it stands</h2></template>

                        <div class="space-y-4">
                            <UiFormField label="Status">
                                <UiSelect
                                    :model-value="ticket.status"
                                    :options="statuses.map((s) => ({ value: s, label: s.replace('_', ' ') }))"
                                    @update:model-value="patch('status', $event)"
                                />
                            </UiFormField>

                            <UiFormField label="Priority">
                                <UiSelect
                                    :model-value="ticket.priority"
                                    :options="priorities.map((p) => ({ value: p, label: p }))"
                                    @update:model-value="patch('priority', $event)"
                                />
                            </UiFormField>

                            <UiFormField label="Assigned to">
                                <UiSelect
                                    :model-value="ticket.assignedTo ?? ''"
                                    :options="[{ value: '', label: 'Nobody' }, ...assignees]"
                                    @update:model-value="patch('assigned_to', $event || null)"
                                />
                            </UiFormField>
                        </div>
                    </UiCard>

                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">The promise</h2></template>

                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">First response due</dt>
                                <dd :style="ticket.breachedResponse ? { color: 'var(--color-danger-500)' } : {}">
                                    {{ ticket.responseDueAt || 'No contract' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">We first replied</dt>
                                <dd>{{ ticket.firstResponseAt || 'Not yet' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Resolution due</dt>
                                <dd :style="ticket.breachedResolution ? { color: 'var(--color-danger-500)' } : {}">
                                    {{ ticket.resolutionDueAt || '—' }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Resolved</dt>
                                <dd>{{ ticket.resolvedAt || 'Not yet' }}</dd>
                            </div>
                        </dl>

                        <p
                            v-if="ticket.breachedResponse || ticket.breachedResolution"
                            class="mt-4 flex items-start gap-2 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                            style="background: var(--surface-sunken); color: var(--color-danger-500)"
                        >
                            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            {{ ticket.sla }}
                        </p>
                    </UiCard>

                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Context</h2></template>

                        <dl class="space-y-3 text-sm">
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Client</dt>
                                <dd>
                                    <Link :href="`/admin/clients/${ticket.client.id}`" class="hover:underline"
                                          style="color: var(--color-brand-600)">
                                        {{ ticket.client.name }}
                                    </Link>
                                </dd>
                            </div>
                            <div v-if="ticket.project">
                                <dt class="text-xs" style="color: var(--text-muted)">Project</dt>
                                <dd>{{ ticket.project }}</dd>
                            </div>
                            <div v-if="ticket.contract">
                                <dt class="text-xs" style="color: var(--text-muted)">Contract</dt>
                                <dd>{{ ticket.contract }}</dd>
                            </div>
                            <div v-if="ticket.satisfaction">
                                <dt class="text-xs" style="color: var(--text-muted)">They rated it</dt>
                                <dd class="tnum">{{ ticket.satisfaction }} out of 5</dd>
                            </div>
                        </dl>
                    </UiCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
