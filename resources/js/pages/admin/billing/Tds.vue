<script setup>
/**
 * The TDS register (CL14): what clients deducted from our invoices, the
 * Form 16A for each, and whether it matches Form 26AS. A certificate that
 * does not match goes back to the client with what is wrong.
 */
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });

const tones = { declared: 'warning', received: 'brand', verified: 'success', mismatch: 'danger' };

function check(row, matches) {
    const note = matches ? '' : window.prompt('What does not match Form 26AS? The client is told this.', '');
    if (note === null) return;
    router.post(`/admin/billing/tds/${row.id}/check`, { matches, note }, { preserveScroll: true });
}
</script>

<template>
    <Head title="TDS register" />

    <AppLayout title="TDS register" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'TDS register' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="TDS register"
                description="Tax clients deducted at source from our invoices. Each settles its invoice when declared; the Form 16A proves it, so check each one against Form 26AS."
            />

            <DataTable :table="table" empty-title="No TDS yet" empty-description="Clients record TDS on an invoice before they pay it.">
                <template #cell:invoice="{ row }">
                    <Link v-if="row.paymentId" :href="`/admin/billing/${row.paymentId}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.invoice }}</Link>
                    <span v-else>{{ row.invoice }}</span>
                </template>
                <template #cell:certificate_due_on="{ row }">
                    <span :style="{ color: row.overdue ? 'var(--color-danger-500)' : null }">{{ row.certificate_due_on }}</span>
                </template>
                <template #cell:status="{ row }">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        <UiButton v-if="row.hasCertificate" :href="`/admin/billing/tds/${row.id}/certificate`" :inertia="false" variant="ghost" size="xs">Form 16A</UiButton>
                        <template v-if="row.status === 'received'">
                            <UiButton size="xs" variant="secondary" @click="check(row, true)">Matches 26AS</UiButton>
                            <UiButton size="xs" variant="ghost" @click="check(row, false)">Does not</UiButton>
                        </template>
                    </div>
                </template>
                <template #mobileRow="{ row }">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold">{{ row.client }}</span>
                        <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.invoice }} · {{ row.amount }} · {{ row.quarter }} · due {{ row.certificate_due_on }}</p>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
