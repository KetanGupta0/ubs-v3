<script setup>
/**
 * One invoice, on screen.
 *
 * The printed version comes from the PDF rather than from this page, because
 * the PDF is what was issued and a browser's print stylesheet is not a record.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download, CreditCard } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    invoice: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Invoice ${invoice.number}`" />

    <AppLayout
        :title="`Invoice ${invoice.number}`"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Transactions', href: '/client/transactions' },
            { label: invoice.number },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="`Invoice ${invoice.number}`" :description="`Issued ${invoice.issuedAt}`">
                <template #actions>
                    <UiButton href="/client/transactions" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton :href="`/client/invoices/${invoice.id}/pdf`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        Download PDF
                    </UiButton>
                    <UiButton
                        v-if="invoice.status !== 'paid' && invoice.paymentRequestId"
                        :href="`/client/payments/${invoice.paymentRequestId}`"
                        size="sm"
                    >
                        <template #leading><CreditCard class="h-3.5 w-3.5" /></template>
                        Pay
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-start justify-between gap-6">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">From</p>
                        <p class="mt-1 text-sm font-semibold">{{ invoice.billedFrom.name }}</p>
                        <p class="text-xs" style="color: var(--text-muted)">
                            {{ [invoice.billedFrom.city, invoice.billedFrom.state].filter(Boolean).join(', ') }}
                        </p>
                        <p v-if="invoice.billedFrom.gstin" class="text-xs" style="color: var(--text-muted)">
                            GSTIN {{ invoice.billedFrom.gstin }}
                        </p>
                    </div>

                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">Billed to</p>
                        <p class="mt-1 text-sm font-semibold">{{ invoice.billedTo.name }}</p>
                        <p class="text-xs" style="color: var(--text-muted)">
                            {{ [invoice.billedTo.city, invoice.billedTo.state].filter(Boolean).join(', ') }}
                        </p>
                        <p v-if="invoice.billedTo.gstin" class="text-xs" style="color: var(--text-muted)">
                            GSTIN {{ invoice.billedTo.gstin }}
                        </p>
                    </div>

                    <div class="text-right">
                        <UiBadge
                            :tone="invoice.status === 'paid' ? 'success' : invoice.overdue ? 'danger' : 'brand'"
                            size="sm"
                            dot
                        >
                            {{ invoice.status }}
                        </UiBadge>
                        <p v-if="invoice.dueOn" class="mt-1 text-xs" style="color: var(--text-muted)">
                            Due {{ invoice.dueOn }}
                        </p>
                        <p v-if="invoice.placeOfSupply" class="mt-1 text-xs" style="color: var(--text-muted)">
                            Place of supply: {{ invoice.placeOfSupply }}
                        </p>
                    </div>
                </div>

                <div class="-mx-5 mt-6 overflow-x-auto scrollbar-thin sm:mx-0">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wide"
                                style="border-color: var(--border-subtle); color: var(--text-muted)">
                                <th class="px-5 py-2 font-medium sm:px-0">Description</th>
                                <th class="px-3 py-2 font-medium">HSN/SAC</th>
                                <th class="px-3 py-2 text-right font-medium">Qty</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-5 py-2 text-right font-medium sm:px-0">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in invoice.items" :key="index" class="border-b last:border-0"
                                style="border-color: var(--border-subtle)">
                                <td class="px-5 py-2.5 sm:px-0">{{ item.description }}</td>
                                <td class="px-3 py-2.5" style="color: var(--text-muted)">{{ item.hsnSac || '—' }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.quantity }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.unitPrice }}</td>
                                <td class="px-5 py-2.5 text-right tnum sm:px-0">{{ item.amount }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="ml-auto mt-5 max-w-xs space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <dt style="color: var(--text-muted)">Subtotal</dt>
                        <dd class="tnum">{{ invoice.subtotal }}</dd>
                    </div>
                    <div v-for="(part, index) in invoice.taxBreakup" :key="index" class="flex justify-between">
                        <dt style="color: var(--text-muted)">{{ part.label }} at {{ part.rate }}%</dt>
                        <dd class="tnum">{{ part.amount }}</dd>
                    </div>
                    <div class="flex justify-between border-t pt-2 text-base font-semibold"
                         style="border-color: var(--border-strong)">
                        <dt>Total</dt>
                        <dd class="tnum">{{ invoice.total }}</dd>
                    </div>
                    <div v-if="invoice.amountPaid !== '₹0.00'" class="flex justify-between">
                        <dt style="color: var(--text-muted)">Paid</dt>
                        <dd class="tnum">{{ invoice.amountPaid }}</dd>
                    </div>
                    <div v-if="invoice.balance !== '₹0.00'" class="flex justify-between font-semibold">
                        <dt>Balance</dt>
                        <dd class="tnum">{{ invoice.balance }}</dd>
                    </div>
                </dl>

                <p class="mt-3 text-right text-xs" style="color: var(--text-muted)">{{ invoice.totalInWords }}</p>

                <p v-if="invoice.terms" class="mt-6 whitespace-pre-line border-t pt-4 text-xs"
                   style="border-color: var(--border-subtle); color: var(--text-muted)">
                    {{ invoice.terms }}
                </p>
            </UiCard>

            <UiCard v-if="invoice.payments.length">
                <template #header><h2 class="text-base font-semibold">Payments against this invoice</h2></template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="payment in invoice.payments" :key="payment.reference"
                        class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                        <span class="min-w-0 flex-1 text-sm">{{ payment.reference }}</span>
                        <span class="text-xs" style="color: var(--text-muted)">{{ payment.at }}</span>
                        <span class="tnum text-sm">{{ payment.amount }}</span>
                        <UiBadge :tone="payment.status === 'successful' ? 'success' : 'neutral'" size="sm">
                            {{ payment.status }}
                        </UiBadge>
                        <UiButton
                            v-if="payment.receiptable"
                            :href="`/client/receipts/${payment.reference}`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                        >
                            Receipt
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
