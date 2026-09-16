<script setup>
/**
 * Paying one request.
 *
 * The amount is never sent from here. The browser asks the server to open
 * checkout and the server decides what is owed, because a checkout that takes
 * its amount from the page is a lakh rupee invoice payable with one rupee.
 */
import { ref, onMounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Download, CreditCard, ShieldCheck, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import { toast } from '@/support/toast';

const props = defineProps({
    payment: { type: Object, required: true },
    invoice: { type: Object, default: null },
    gateway: { type: Object, default: () => ({}) },
});

const busy = ref(false);
const simulating = ref(null);
const razorpayReady = ref(false);

onMounted(() => {
    if (!props.gateway.live) return;

    // Loaded only when a real provider is configured, so a development
    // environment does not reach out to a third party on every page view.
    const script = document.createElement('script');
    script.src = 'https://checkout.razorpay.com/v1/checkout.js';
    script.onload = () => (razorpayReady.value = true);
    document.head.appendChild(script);
});

async function pay() {
    busy.value = true;

    try {
        const response = await fetch(`/client/payments/${props.payment.id}/checkout`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error('Checkout could not be opened.');
        }

        const data = await response.json();

        if (data.order.simulated) {
            simulating.value = data;
            return;
        }

        openRazorpay(data);
    } catch (error) {
        toast.error(error.message || 'Something went wrong opening checkout.');
    } finally {
        busy.value = false;
    }
}

function openRazorpay(data) {
    if (!window.Razorpay) {
        toast.error('The payment window could not load. Check your connection and try again.');
        return;
    }

    const checkout = new window.Razorpay({
        key: data.order.publicKey,
        amount: data.order.amount,
        currency: data.order.currency,
        order_id: data.order.id,
        name: data.name,
        description: data.description,
        prefill: data.prefill,
        theme: { color: '#4f46e5' },
        handler: (result) => confirm(data.transaction, result),
        modal: {
            ondismiss: () => toast.info('Payment window closed. Nothing has been charged.'),
        },
    });

    checkout.open();
}

function confirm(reference, payload) {
    router.post(`/client/payments/${reference}/confirm`, payload, { preserveScroll: true });
}

function simulate(outcome) {
    const data = simulating.value;
    simulating.value = null;
    confirm(data.transaction, { outcome });
}
</script>

<template>
    <Head :title="payment.title" />

    <AppLayout
        :title="payment.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Payments', href: '/client/payments' },
            { label: payment.reference },
        ]"
    >
        <div class="mx-auto max-w-2xl space-y-5">
            <PageHeader :title="payment.title" :description="payment.description">
                <template #actions>
                    <UiButton href="/client/payments" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs" style="color: var(--text-muted)">Amount due</p>
                        <p class="text-3xl font-semibold tnum">{{ payment.total }}</p>
                    </div>

                    <div class="text-right">
                        <UiBadge
                            :tone="payment.status === 'paid' ? 'success' : payment.overdue ? 'danger' : 'brand'"
                            size="sm"
                            dot
                        >
                            {{ payment.statusLabel }}
                        </UiBadge>
                        <p v-if="payment.dueOn" class="mt-1 text-xs" style="color: var(--text-muted)">
                            Due {{ payment.dueOn }}
                        </p>
                    </div>
                </div>

                <dl class="mt-5 space-y-1.5 border-t pt-4 text-sm" style="border-color: var(--border-subtle)">
                    <div class="flex justify-between">
                        <dt style="color: var(--text-muted)">Subtotal</dt>
                        <dd class="tnum">{{ payment.subtotal }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt style="color: var(--text-muted)">Tax at {{ payment.taxRate }}%</dt>
                        <dd class="tnum">{{ payment.tax }}</dd>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <dt>Total</dt>
                        <dd class="tnum">{{ payment.total }}</dd>
                    </div>
                </dl>

                <p v-if="payment.notes" class="mt-4 whitespace-pre-line text-xs" style="color: var(--text-muted)">
                    {{ payment.notes }}
                </p>

                <div v-if="payment.payable" class="mt-5 flex flex-wrap gap-2">
                    <UiButton :loading="busy" @click="pay">
                        <template #leading><CreditCard class="h-4 w-4" /></template>
                        Pay {{ payment.total }}
                    </UiButton>
                    <UiButton
                        v-if="invoice"
                        :href="`/client/invoices/${invoice.id}/pdf`"
                        :inertia="false"
                        variant="secondary"
                    >
                        <template #leading><Download class="h-4 w-4" /></template>
                        Download the invoice
                    </UiButton>
                </div>

                <p class="mt-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                    <ShieldCheck class="h-3.5 w-3.5" />
                    <span v-if="gateway.live">
                        Card details are entered on the provider's window and never reach us.
                    </span>
                    <span v-else>
                        No payment provider is configured, so this environment simulates the result.
                    </span>
                </p>
            </UiCard>

            <UiCard v-if="invoice">
                <template #header>
                    <h2 class="text-base font-semibold">Invoice</h2>
                </template>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">{{ invoice.number }}</p>
                        <p class="text-xs" style="color: var(--text-muted)">Issued {{ invoice.issuedAt }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <UiButton :href="`/client/invoices/${invoice.id}`" variant="ghost" size="sm">View</UiButton>
                        <UiButton
                            :href="`/client/invoices/${invoice.id}/pdf`"
                            :inertia="false"
                            variant="secondary"
                            size="sm"
                        >
                            <template #leading><Download class="h-3.5 w-3.5" /></template>
                            PDF
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>

        <!-- The development stand in, so the whole path can be walked without a provider. -->
        <UiModal
            :open="Boolean(simulating)"
            title="Simulated payment"
            description="No provider is configured in this environment, so nothing is charged. Choose what the provider should report back."
            @close="simulating = null"
        >
            <p class="flex items-start gap-2 text-sm" style="color: var(--text-muted)">
                <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-warn-500)" />
                This screen does not appear once Razorpay keys are set.
            </p>

            <template #footer>
                <UiButton variant="ghost" @click="simulate('failure')">Simulate a failure</UiButton>
                <UiButton @click="simulate('success')">Simulate success</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
