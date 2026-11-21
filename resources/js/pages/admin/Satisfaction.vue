<script setup>
/**
 * How satisfied clients are (CL18): ticket ratings over the last 90 days,
 * and what they said at handover. A quote becomes a testimonial draft only
 * with the client's written consent, credited as they asked.
 */
import { Head, router } from '@inertiajs/vue3';
import { Quote } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';

defineProps({
    table: { type: Object, required: true },
    tickets: { type: Object, required: true },
    canMakeTestimonials: { type: Boolean, default: false },
});

function draft(row) {
    if (window.confirm(`Add "${row.quote}" as a draft testimonial, credited to ${row.creditAs}? It stays unpublished until somebody publishes it.`)) {
        router.post(`/admin/satisfaction/${row.id}/testimonial`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Satisfaction" />

    <AppLayout title="Satisfaction" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Satisfaction' }]">
        <div class="space-y-5">
            <PageHeader title="Satisfaction" description="Ticket ratings, and what clients said when a project was handed over." />

            <div class="grid gap-3 sm:grid-cols-3">
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Tickets rated, last 90 days</p><p class="mt-1 text-2xl font-semibold tnum">{{ tickets.count }}</p></UiCard>
                <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Average rating</p><p class="mt-1 text-2xl font-semibold tnum">{{ tickets.average ? `${tickets.average} / 5` : '—' }}</p></UiCard>
                <UiCard padding="p-4" class="sm:col-span-1">
                    <p class="text-xs" style="color: var(--text-muted)">Latest words with a rating</p>
                    <p v-if="!tickets.recent.length" class="mt-1 text-sm" style="color: var(--text-muted)">None yet.</p>
                    <ul v-else class="mt-1 space-y-1 text-sm">
                        <li v-for="ticket in tickets.recent.slice(0, 3)" :key="ticket.id">{{ ticket.rating }}/5 · “{{ ticket.comment }}”</li>
                    </ul>
                </UiCard>
            </div>

            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">At handover</h2>
            <DataTable :table="table" empty-title="No handover answers yet" empty-description="Clients are asked after they acknowledge a handover pack.">
                <template #cell:rating="{ row }">
                    <UiBadge :tone="row.rating >= 4 ? 'success' : row.rating === 3 ? 'warning' : 'danger'" size="sm">{{ row.rating }}/5</UiBadge>
                </template>
                <template #cell:quote="{ row }">
                    <p class="max-w-md text-sm">{{ row.quote }}</p>
                    <p v-if="row.couldImprove" class="mt-1 max-w-md text-xs" style="color: var(--text-muted)">Could be better: {{ row.couldImprove }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                        <UiBadge v-if="row.consent" tone="brand" size="sm">May be published, as {{ row.creditAs }}</UiBadge>
                        <span v-if="row.testimonialId" style="color: var(--text-muted)">A testimonial draft exists</span>
                        <UiButton v-else-if="row.consent && canMakeTestimonials" size="xs" variant="secondary" @click="draft(row)">
                            <template #leading><Quote class="h-3 w-3" /></template>
                            Make a testimonial draft
                        </UiButton>
                    </div>
                </template>
                <template #mobileRow="{ row }">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold">{{ row.project }}</span>
                        <UiBadge :tone="row.rating >= 4 ? 'success' : row.rating === 3 ? 'warning' : 'danger'" size="sm">{{ row.rating }}/5</UiBadge>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.client }} · {{ row.created_at }}</p>
                    <p class="mt-1 text-sm">{{ row.quote }}</p>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
