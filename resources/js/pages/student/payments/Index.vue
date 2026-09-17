<script setup>
/**
 * Course fees and everything paid so far.
 *
 * The same ledger the client portal renders, because it is the same money:
 * one numbering sequence, one receipt renderer, one definition of paid.
 */
import { Head } from '@inertiajs/vue3';
import { Receipt, FileText, Download, Printer, AlertTriangle } from 'lucide-vue-next';

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
    pending: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
});

const tones = { successful: 'success', failed: 'danger', refunded: 'warning', created: 'neutral', pending: 'brand' };
</script>

<template>
    <Head title="Fees" />

    <AppLayout title="Fees" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Fees' }]">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader
                title="Fees and payments"
                description="What is due, what has been paid, and a receipt for every one of them."
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

            <!-- ------------------------------------------------- still due -->
            <UiCard v-if="pending.length">
                <template #header>
                    <h2 class="text-base font-semibold">Waiting to be paid</h2>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="payment in pending"
                        :key="payment.id"
                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <AlertTriangle
                            v-if="payment.overdue"
                            class="h-4 w-4 shrink-0"
                            style="color: var(--color-warn-500)"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ payment.title }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ payment.reference }}
                                <span v-if="payment.dueOn"> · due {{ payment.dueOn }}</span>
                            </span>
                        </span>

                        <span class="text-sm font-semibold tnum">{{ payment.total }}</span>

                        <UiButton :href="`/student/payments/${payment.id}`" size="sm">Pay</UiButton>
                    </li>
                </ul>
            </UiCard>

            <!-- --------------------------------------------------- ledger -->
            <DataTable
                :table="table"
                search-placeholder="Search by reference or method…"
                empty-title="No payments yet"
                empty-description="Anything you pay towards a course shows up here, successful or not."
                :only="['table']"
            >
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>

                <template #cell:invoice="{ row }">
                    <span>{{ row.invoice }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton
                        v-if="row.receiptable"
                        :href="`/student/receipts/${row.reference}`"
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
                                :href="`/student/receipts/${row.reference}`"
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

            <!-- ------------------------------------------------- invoices -->
            <UiCard v-if="invoices.length">
                <template #header>
                    <h2 class="text-base font-semibold">Invoices</h2>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="invoice in invoices"
                        :key="invoice.id"
                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <FileText class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ invoice.number }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                Issued {{ invoice.issuedAt }}
                            </span>
                        </span>

                        <UiBadge :tone="invoice.status === 'paid' ? 'success' : 'brand'" size="sm">
                            {{ invoice.status }}
                        </UiBadge>
                        <span class="text-sm font-semibold tnum">{{ invoice.total }}</span>

                        <UiButton
                            :href="`/student/invoices/${invoice.id}/pdf`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                        >
                            <template #leading><Download class="h-3 w-3" /></template>
                            PDF
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
