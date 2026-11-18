<script setup>
/** Finishing a month's service report (CL17): the ticket figures are worked out; add what only we know. */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({ report: { type: Object, required: true } });

const form = useForm({ uptime_percent: props.report.uptime_percent ?? '', hours_used: props.report.hours_used ?? '', work_done: props.report.work_done, publish: false });
const published = props.report.status === 'published';

function save(publish) {
    if (publish && !window.confirm(`Publish the ${props.report.month} report to ${props.report.client}? It goes into their documents and cannot be changed.`)) return;
    form.publish = publish;
    form.put(`/admin/service-reports/${props.report.id}`, { preserveScroll: true });
}

const percent = (value) => (value === null ? '—' : `${value}%`);
</script>

<template>
    <Head :title="`Service report · ${report.month}`" />

    <AppLayout :title="`Service report · ${report.month}`" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Service reports', href: '/admin/service-reports' }, { label: report.month }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="`${report.month} · ${report.client}`" :description="report.contract">
                <template #actions>
                    <UiButton href="/admin/service-reports" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Service reports
                    </UiButton>
                    <UiButton :href="`/admin/service-reports/${report.id}/preview`" :inertia="false" target="_blank" variant="secondary" size="sm">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Preview the PDF
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-4">
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Tickets raised</p><p class="mt-1 text-2xl font-semibold tnum">{{ report.figures.opened }}</p></UiCard>
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Resolved</p><p class="mt-1 text-2xl font-semibold tnum">{{ report.figures.resolved }}</p></UiCard>
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Responded in {{ report.figures.responseHours }}h</p><p class="mt-1 text-2xl font-semibold tnum">{{ percent(report.figures.responseMet) }}</p></UiCard>
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Resolved in {{ report.figures.resolutionHours }}h</p><p class="mt-1 text-2xl font-semibold tnum">{{ percent(report.figures.resolutionMet) }}</p></UiCard>
            </div>

            <UiCard>
                <div class="mb-4 flex items-center gap-2">
                    <UiBadge :tone="published ? 'success' : 'warning'" dot>{{ published ? `Published ${report.publishedAt}` : 'To finish' }}</UiBadge>
                </div>
                <form class="space-y-4" @submit.prevent="save(false)">
                    <fieldset :disabled="published" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiFormField label="Uptime (%)" hint="Blank if not measured for this contract." :error="form.errors.uptime_percent">
                                <UiInput v-model="form.uptime_percent" type="number" min="0" max="100" step="0.001" />
                            </UiFormField>
                            <UiFormField :label="report.figures.includedHours ? `Hours used (of ${report.figures.includedHours})` : 'Hours used'" :error="form.errors.hours_used">
                                <UiInput v-model="form.hours_used" type="number" min="0" step="0.25" />
                            </UiFormField>
                        </div>
                        <UiFormField label="Work done" hint="Markdown. Updates, fixes, anything worth the client knowing." :error="form.errors.work_done">
                            <UiTextarea v-model="form.work_done" :rows="8" />
                        </UiFormField>
                    </fieldset>
                    <div v-if="!published" class="flex flex-wrap justify-end gap-2">
                        <UiButton type="submit" variant="secondary" :loading="form.processing && !form.publish">Save</UiButton>
                        <UiButton type="button" :loading="form.processing && form.publish" @click="save(true)">
                            <template #leading><Send class="h-4 w-4" /></template>
                            Publish to the client
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard v-if="report.figures.tickets.length">
                <h2 class="text-base font-semibold">Tickets</h2>
                <ul class="mt-3 divide-y text-sm">
                    <li v-for="ticket in report.figures.tickets" :key="ticket.reference" class="flex flex-wrap items-center gap-2 py-2">
                        <span class="font-mono text-xs">{{ ticket.reference }}</span>
                        <span class="min-w-0 flex-1">{{ ticket.subject }}</span>
                        <UiBadge v-if="ticket.responseMet === false" tone="danger" size="sm">Late response</UiBadge>
                        <UiBadge v-if="ticket.resolutionMet === false" tone="danger" size="sm">Late resolution</UiBadge>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
