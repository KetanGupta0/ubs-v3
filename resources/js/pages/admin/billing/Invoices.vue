<script setup>
import { Head } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
});

const tones = { paid: 'success', issued: 'brand', partially_paid: 'warning', cancelled: 'neutral', refunded: 'warning' };
</script>

<template>
    <Head title="Invoices" />

    <AppLayout title="Invoices" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Invoices' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Invoices"
                description="Every invoice issued, in one numbered sequence per financial year."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Issued" :value="totals.issued ?? '₹0.00'" />
                <StatTile label="Collected" :value="totals.collected ?? '₹0.00'" tone="success" />
                <StatTile label="Outstanding" :value="totals.outstanding ?? '₹0.00'" tone="warning" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by invoice number…"
                empty-title="No invoices match"
                empty-description="Try clearing the filters."
                :only="['table']"
            >
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>
                        {{ row.status.replace('_', ' ') }}
                    </UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton
                        :href="`/admin/invoices/${row.id}/pdf`"
                        :inertia="false"
                        variant="ghost"
                        size="xs"
                    >
                        <template #leading><Download class="h-3 w-3" /></template>
                        PDF
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.number }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.person }} · {{ row.issued_at }}
                                </span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-semibold tnum">{{ row.total }}</span>
                                <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm">{{ row.status }}</UiBadge>
                            </span>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
