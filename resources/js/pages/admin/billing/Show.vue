<script setup>
/**
 * One payment request: what was asked for, and everything that has happened to it.
 */
import { ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download, Ban, Banknote, Undo2, FileMinus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    payment: { type: Object, required: true },
    invoice: { type: Object, default: null },
    transactions: { type: Array, default: () => [] },
    gatewayIsLive: { type: Boolean, default: false },
});

const offlineOpen = ref(false);

/* Credit notes against the invoice (CL14). */
const creditForm = useForm({ amount: '', reason: '' });

function issueCredit() {
    if (window.confirm(`Issue a credit note for ₹${creditForm.amount} before tax against ${props.invoice.number}? It cannot be changed afterwards.`)) {
        creditForm.post(`/admin/invoices/${props.invoice.id}/credit-notes`, { preserveScroll: true, onSuccess: () => creditForm.reset() });
    }
}
const refunding = ref(null);

const offlineForm = useForm({
    amount: props.invoice?.balanceValue ?? null,
    method: 'bank transfer',
    note: '',
});

const refundForm = useForm({ amount: null, reason: '' });

function recordOffline() {
    offlineForm.post(`/admin/billing/${props.payment.id}/offline`, {
        preserveScroll: true,
        onSuccess: () => (offlineOpen.value = false),
    });
}

function openRefund(transaction) {
    refunding.value = transaction;
    refundForm.clearErrors();
    refundForm.amount = null;
    refundForm.reason = '';
}

function submitRefund() {
    refundForm.post(`/admin/transactions/${refunding.value.id}/refund`, {
        preserveScroll: true,
        onSuccess: () => (refunding.value = null),
    });
}

function cancel() {
    if (confirm('Cancel this request? The client will no longer be able to pay it.')) {
        router.post(`/admin/billing/${props.payment.id}/cancel`, {}, { preserveScroll: true });
    }
}

const tones = { successful: 'success', failed: 'danger', refunded: 'warning', created: 'neutral', pending: 'brand' };
</script>

<template>
    <Head :title="payment.reference" />

    <AppLayout
        :title="payment.title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Billing', href: '/admin/billing' },
            { label: payment.reference },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="payment.title" :description="payment.description">
                <template #actions>
                    <UiButton href="/admin/billing" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton
                        v-if="invoice"
                        :href="`/admin/invoices/${invoice.id}/pdf`"
                        :inertia="false"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        Invoice PDF
                    </UiButton>
                    <UiButton
                        v-if="payment.status === 'pending' && invoice"
                        variant="secondary"
                        size="sm"
                        @click="offlineOpen = true"
                    >
                        <template #leading><Banknote class="h-3.5 w-3.5" /></template>
                        Record a payment
                    </UiButton>
                    <UiButton v-if="payment.status === 'pending'" variant="ghost" size="sm" @click="cancel">
                        <template #leading><Ban class="h-3.5 w-3.5" /></template>
                        Cancel
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs" style="color: var(--text-muted)">Amount</p>
                        <p class="text-3xl font-semibold tnum">{{ payment.total }}</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            {{ payment.subtotal }} plus {{ payment.tax }} tax
                        </p>
                    </div>

                    <div class="text-right">
                        <UiBadge
                            :tone="payment.status === 'paid' ? 'success' : payment.overdue ? 'danger' : 'brand'"
                            size="sm"
                            dot
                        >
                            {{ payment.status }}
                        </UiBadge>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            Raised {{ payment.raisedAt }}<span v-if="payment.raisedBy"> by {{ payment.raisedBy }}</span>
                        </p>
                        <p v-if="payment.dueOn" class="text-xs" style="color: var(--text-muted)">
                            Due {{ payment.dueOn }}
                        </p>
                    </div>
                </div>

                <dl class="mt-5 grid gap-4 border-t pt-4 text-sm sm:grid-cols-3" style="border-color: var(--border-subtle)">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Raised on</dt>
                        <dd>
                            <Link
                                :href="`/admin/${payment.user.role}s/${payment.user.id}`"
                                class="hover:underline"
                                style="color: var(--color-brand-600)"
                            >
                                {{ payment.user.name }}
                            </Link>
                        </dd>
                    </div>
                    <div v-if="invoice">
                        <dt class="text-xs" style="color: var(--text-muted)">Invoice</dt>
                        <dd>{{ invoice.number }} · {{ invoice.issuedAt }}</dd>
                    </div>
                    <div v-if="invoice">
                        <dt class="text-xs" style="color: var(--text-muted)">Balance</dt>
                        <dd class="tnum">{{ invoice.balance }}</dd>
                    </div>
                </dl>

                <p v-if="payment.notes" class="mt-4 whitespace-pre-line rounded-[var(--radius-field)] px-3 py-2 text-xs"
                   style="background: var(--surface-sunken); color: var(--text-muted)">
                    {{ payment.notes }}
                </p>
            </UiCard>

            <!-- ------------------------------------ settled otherwise (CL14) -->
            <UiCard v-if="invoice">
                <template #header>
                    <h2 class="text-base font-semibold">TDS and credit notes</h2>
                </template>
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs" style="color: var(--text-muted)">TDS deducted by the client</dt><dd class="tnum">{{ invoice.tds ?? '—' }}</dd></div>
                    <div><dt class="text-xs" style="color: var(--text-muted)">Credited</dt><dd class="tnum">{{ invoice.credited ?? '—' }}</dd></div>
                </dl>
                <ul v-if="invoice.creditNotes.length" class="mt-4 space-y-2 text-sm">
                    <li v-for="note in invoice.creditNotes" :key="note.id" class="flex flex-wrap items-center gap-2">
                        <FileMinus class="h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                        <span class="font-medium">{{ note.number }}</span>
                        <span class="tnum">{{ note.total }}</span>
                        <span style="color: var(--text-muted)">· {{ note.issuedAt }} · {{ note.reason }}</span>
                        <UiButton :href="`/admin/credit-notes/${note.id}/pdf`" :inertia="false" variant="ghost" size="xs">PDF</UiButton>
                    </li>
                </ul>
                <form v-if="invoice.creditable > 0 && !['cancelled', 'refunded'].includes(invoice.status)" class="mt-4 space-y-3 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="issueCredit">
                    <p class="text-xs" style="color: var(--text-muted)">Up to ₹{{ invoice.creditable.toLocaleString('en-IN') }} of taxable value is left to credit. Tax is added at the invoice's own rates.</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <UiFormField label="Amount before tax (₹)" :error="creditForm.errors.amount">
                            <UiInput v-model="creditForm.amount" type="number" step="0.01" min="0.01" :max="invoice.creditable" />
                        </UiFormField>
                        <UiFormField label="Reason" class="sm:col-span-2" :error="creditForm.errors.reason">
                            <UiInput v-model="creditForm.reason" maxlength="500" placeholder="Discount agreed on 20 Nov" />
                        </UiFormField>
                    </div>
                    <div class="flex justify-end">
                        <UiButton type="submit" size="sm" variant="secondary" :loading="creditForm.processing" :disabled="!creditForm.amount || creditForm.reason.trim().length < 5">Issue a credit note</UiButton>
                    </div>
                </form>
            </UiCard>

            <!-- ---------------------------------------------- attempts -->
            <UiCard>
                <template #header>
                    <h2 class="text-base font-semibold">Payment attempts</h2>
                </template>

                <p v-if="!transactions.length" class="text-sm" style="color: var(--text-muted)">
                    Nothing attempted yet.
                </p>

                <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="transaction in transactions" :key="transaction.id"
                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ transaction.reference }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ transaction.at }} · {{ transaction.gateway }}{{ transaction.method ? ` · ${transaction.method}` : '' }}
                            </span>
                            <span v-if="transaction.failureReason" class="block text-xs" style="color: var(--color-danger-500)">
                                {{ transaction.failureReason }}
                            </span>
                        </span>

                        <span class="tnum text-sm">{{ transaction.amount }}</span>

                        <UiBadge :tone="tones[transaction.status] ?? 'neutral'" size="sm" dot>
                            {{ transaction.status }}
                        </UiBadge>

                        <UiButton
                            v-if="transaction.refundable"
                            variant="ghost"
                            size="xs"
                            @click="openRefund(transaction)"
                        >
                            <template #leading><Undo2 class="h-3 w-3" /></template>
                            Refund
                        </UiButton>
                    </li>
                </ul>

                <p v-if="!gatewayIsLive" class="mt-4 text-xs" style="color: var(--text-muted)">
                    No payment provider is configured, so online payments are simulated in this environment.
                </p>
            </UiCard>
        </div>

        <!-- -------------------------------------------- record a payment -->
        <UiModal
            :open="offlineOpen"
            title="Record a payment"
            description="For money that arrived outside the gateway: a bank transfer, a cheque, cash."
            @close="offlineOpen = false"
        >
            <form id="offline-form" class="space-y-4" @submit.prevent="recordOffline">
                <UiFormField label="Amount (₹)" required :error="offlineForm.errors.amount">
                    <UiInput v-model="offlineForm.amount" type="number" step="0.01" min="1" />
                </UiFormField>

                <UiFormField label="How it arrived" required :error="offlineForm.errors.method">
                    <UiSelect
                        v-model="offlineForm.method"
                        :options="[
                            { value: 'bank transfer', label: 'Bank transfer' },
                            { value: 'cheque', label: 'Cheque' },
                            { value: 'upi', label: 'UPI' },
                            { value: 'cash', label: 'Cash' },
                        ]"
                    />
                </UiFormField>

                <UiFormField label="Reference or note" :error="offlineForm.errors.note">
                    <UiInput v-model="offlineForm.note" placeholder="UTR or cheque number" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="offlineOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="offline-form" :loading="offlineForm.processing">Record it</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------------ refund -->
        <UiModal
            :open="Boolean(refunding)"
            title="Refund this payment"
            description="This records the refund here. Processing it with the provider is a separate step."
            @close="refunding = null"
        >
            <form id="refund-form" class="space-y-4" @submit.prevent="submitRefund">
                <UiFormField label="Amount (₹)" required :error="refundForm.errors.amount">
                    <UiInput v-model="refundForm.amount" type="number" step="0.01" min="1" />
                </UiFormField>

                <UiFormField label="Why" required :error="refundForm.errors.reason">
                    <UiTextarea v-model="refundForm.reason" :rows="3" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="refunding = null">Cancel</UiButton>
                <UiButton type="submit" form="refund-form" variant="danger" :loading="refundForm.processing">
                    Record the refund
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
