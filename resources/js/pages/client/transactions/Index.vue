<script setup>
/**
 * The ledger.
 *
 * Failures are listed alongside successes, because "I tried three times and it
 * kept failing" is a question somebody asks when they are already annoyed, and
 * a statement of successes alone cannot answer it.
 */
import { Head, Link } from '@inertiajs/vue3';
import { Download, Receipt, FileText, Printer } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    summary: { type: Object, default: () => ({}) },
    invoices: { type: Array, default: () => [] },
});

const tones = { successful: 'success', failed: 'danger', refunded: 'warning', created: 'neutral', pending: 'brand' };
</script>

<template>
    <Head title="Transactions" />

    <AppLayout title="Transactions" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Transactions' }]">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader
                title="Transactions"
                description="Every payment attempt on your account. Download the whole statement, or a single receipt."
            >
                <template #actions>
                    <UiButton variant="secondary" size="sm" @click="print()">
                        <template #leading><Printer class="h-3.5 w-3.5" /></template>
                        Print
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-3">
                <StatTile label="Billed to date" :value="summary.billed ?? '₹0.00'" />
                <StatTile label="Paid" :value="summary.paid ?? '₹0.00'" tone="success" />
                <StatTile label="Outstanding" :value="summary.due ?? '₹0.00'" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by reference or method…"
                empty-title="No transactions yet"
                empty-description="Payments will appear here as soon as one goes through."
                :only="['table']"
            >
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>

                <template #cell:invoice="{ row }">
                    <Link v-if="row.invoiceId" :href="`/client/invoices/${row.invoiceId}`" class="hover:underline">
                        {{ row.invoice }}
                    </Link>
                    <span v-else>{{ row.invoice }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton
                        v-if="row.receiptable"
                        :href="`/client/receipts/${row.reference}`"
                        :inertia="false"
                        variant="ghost"
                        size="xs"
                    >
                        <template #leading><Receipt class="h-3 w-3" /></template>
                        Receipt
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium">{{ row.description }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.created_at }} · {{ row.reference }}
                                </span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-semibold tnum">{{ row.amount }}</span>
                                <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm">{{ row.status }}</UiBadge>
                            </span>
                        </div>
                        <div v-if="row.receiptable" class="mt-2">
                            <UiButton
                                :href="`/client/receipts/${row.reference}`"
                                :inertia="false"
                                variant="ghost"
                                size="xs"
                            >
                                <template #leading><Receipt class="h-3 w-3" /></template>
                                Download receipt
                            </UiButton>
                        </div>
                    </div>
                </template>
            </DataTable>

            <!-- ---------------------------------------------------- invoices -->
            <UiCard v-if="invoices.length">
                <template #header>
                    <h2 class="text-base font-semibold">Invoices</h2>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="invoice in invoices" :key="invoice.id" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <FileText class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                        <span class="min-w-0 flex-1">
                            <Link :href="`/client/invoices/${invoice.id}`" class="text-sm font-medium hover:underline">
                                {{ invoice.number }}
                            </Link>
                            <span class="block text-xs" style="color: var(--text-muted)">Issued {{ invoice.issuedAt }}</span>
                        </span>

                        <span class="tnum text-sm">{{ invoice.total }}</span>

                        <UiBadge
                            :tone="invoice.status === 'paid' ? 'success' : invoice.overdue ? 'danger' : 'neutral'"
                            size="sm"
                        >
                            {{ invoice.status }}
                        </UiBadge>

                        <UiButton
                            :href="`/client/invoices/${invoice.id}/pdf`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                            icon
                            aria-label="Download invoice"
                        >
                            <Download class="h-3.5 w-3.5" />
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
