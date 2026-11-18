<script setup>
/**
 * One shop order from our side (§4): who bought it and for whom, what it came
 * to, the payment and invoice, and what it gave: licences with their sites,
 * plans, and add-on work for us to deliver.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileText, KeyRound, Receipt, Wrench } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    order: { type: Object, required: true },
    licences: { type: Array, default: () => [] },
    subscriptions: { type: Array, default: () => [] },
    mayManage: { type: Boolean, default: false },
});

const tone = { paid: 'success', pending: 'warning', cancelled: 'neutral', refunded: 'danger', partially_refunded: 'info' };

/** Work on the order that is ours to arrange. */
const ourWork = computed(() => props.order.lines.flatMap((line) => line.addons.filter((addon) => addon.kind === 'service').map((addon) => addon.description)));

const cancelling = ref(false);
const cancelForm = useForm({ reason: '' });

function openCancel() {
    blankForm(cancelForm, { reason: '' });
    cancelling.value = true;
}

function cancel() {
    cancelForm.post(`/admin/orders/${props.order.id}/cancel`, { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
}

function revoke(licence) {
    const reason = window.prompt(`Withdraw the ${licence.product} licence? Say why: the holder sees it.`);
    if (reason) router.post(`/admin/licences/${licence.id}/revoke`, { reason }, { preserveScroll: true });
}

function restore(licence) {
    if (window.confirm('Restore this licence? Its key works again.')) router.post(`/admin/licences/${licence.id}/restore`, {}, { preserveScroll: true });
}

function release(licence, site) {
    if (window.confirm(`Free the place ${site.domain} holds?`)) router.delete(`/admin/licences/${licence.id}/sites/${site.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <AppLayout :title="`Order ${order.number}`" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Shop orders', href: '/admin/orders' }, { label: order.number }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="`Order ${order.number}`" :description="`Placed ${order.placedAt}${order.buyer ? ` by ${order.buyer.name} (${order.buyer.email})` : ''}, for ${order.client.name}.`">
                <template #actions>
                    <UiButton href="/admin/orders" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Orders
                    </UiButton>
                    <UiButton :href="`/admin/clients/${order.client.id}`" variant="ghost" size="sm">The client</UiButton>
                    <UiButton v-if="order.canCancel" variant="secondary" size="sm" @click="openCancel">Cancel the order</UiButton>
                </template>
            </PageHeader>

            <UiCard v-if="ourWork.length && order.status === 'paid'">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <Wrench class="h-4 w-4" />
                    For us to arrange
                </p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    <li v-for="work in ourWork" :key="work">{{ work }}</li>
                </ul>
                <p class="mt-2 text-xs" style="color: var(--text-muted)">Raise a project or a ticket for it from the client's page, so it is tracked like other work.</p>
            </UiCard>

            <UiCard>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <UiBadge :tone="tone[order.status] ?? 'neutral'" dot>{{ order.statusLabel }}</UiBadge>
                        <p v-if="order.paidAt" class="mt-1 text-xs" style="color: var(--text-muted)">Paid {{ order.paidAt }}</p>
                        <p v-if="order.cancelledAt" class="mt-1 text-xs" style="color: var(--text-muted)">Cancelled {{ order.cancelledAt }}: {{ order.cancelReason }}</p>
                    </div>
                    <div v-if="order.payment" class="flex flex-wrap gap-2">
                        <UiButton size="sm" variant="secondary" :href="`/admin/billing/${order.payment.id}`">
                            <template #leading><Receipt class="h-3.5 w-3.5" /></template>
                            Payment {{ order.payment.reference }}
                        </UiButton>
                        <UiButton v-if="order.payment.invoice" size="sm" variant="secondary" :href="`/admin/invoices/${order.payment.invoice.id}/pdf`" :inertia="false">
                            <template #leading><FileText class="h-3.5 w-3.5" /></template>
                            {{ order.payment.invoice.number }}
                        </UiButton>
                    </div>
                </div>

                <ul class="mt-5 divide-y">
                    <li v-for="line in order.lines" :key="line.description" class="py-3">
                        <div class="flex items-start justify-between gap-4 text-sm">
                            <span class="font-semibold">{{ line.description }} <span class="font-normal text-xs" style="color: var(--text-muted)">SAC {{ line.sac }}</span></span>
                            <span class="text-right tnum">{{ line.amount }}<span v-if="line.discount" class="block text-xs" style="color: var(--text-muted)">− {{ line.discount }}</span></span>
                        </div>
                        <ul v-if="line.addons.length" class="mt-1.5 space-y-1 pl-3">
                            <li v-for="addon in line.addons" :key="addon.description" class="flex justify-between gap-4 text-sm">
                                <span>{{ addon.description }}</span>
                                <span class="text-right tnum">{{ addon.amount }}<span v-if="addon.discount" class="block text-xs" style="color: var(--text-muted)">− {{ addon.discount }}</span></span>
                            </li>
                        </ul>
                    </li>
                </ul>

                <dl class="ml-auto mt-3 max-w-xs space-y-1.5 border-t pt-3 text-sm" style="border-color: var(--border-subtle)">
                    <div class="flex justify-between"><dt style="color: var(--text-muted)">Subtotal</dt><dd class="tnum">{{ order.subtotal }}</dd></div>
                    <div v-for="coupon in order.coupons" :key="coupon.code" class="flex justify-between"><dt style="color: var(--text-muted)">Code {{ coupon.code }} <span class="text-xs">({{ coupon.status }})</span></dt><dd class="tnum">− {{ coupon.amount }}</dd></div>
                    <div class="flex justify-between"><dt style="color: var(--text-muted)">Taxable value</dt><dd class="tnum">{{ order.taxable }}</dd></div>
                    <div v-for="part in order.breakup" :key="part.label" class="flex justify-between"><dt style="color: var(--text-muted)">{{ part.label }}</dt><dd class="tnum">{{ part.amount }}</dd></div>
                    <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="tnum">{{ order.total }}</dd></div>
                    <div v-if="order.payment && order.payment.refunded !== '₹0.00'" class="flex justify-between"><dt style="color: var(--text-muted)">Refunded</dt><dd class="tnum">{{ order.payment.refunded }}</dd></div>
                </dl>

                <p class="mt-4 text-xs" style="color: var(--text-muted)">
                    Billed to {{ order.billing.name }}<template v-if="order.billing.gstin">, GSTIN {{ order.billing.gstin }}</template><template v-if="order.billing.city">, {{ order.billing.city }}</template>, {{ order.billing.state }}.
                </p>
            </UiCard>

            <UiCard v-for="licence in licences" :key="licence.id">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-base font-semibold"><KeyRound class="h-4 w-4" />{{ licence.product }}, {{ licence.tier }}</h2>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                            {{ licence.maskedKey ?? 'Key not made yet' }} · {{ licence.sites }}, {{ licence.sitesInUse }} in use · updates until {{ licence.updatesUntil }}<template v-if="licence.supportUntil"> · support until {{ licence.supportUntil }}</template> · {{ licence.downloads }} {{ licence.downloads === 1 ? 'download' : 'downloads' }}<template v-if="licence.lastChecked"> · last checked {{ licence.lastChecked }}</template>
                        </p>
                        <p v-if="!licence.active" class="mt-1 text-xs" style="color: var(--color-danger-600)">Withdrawn: {{ licence.revokedReason }}</p>
                    </div>
                    <div v-if="mayManage" class="flex gap-1">
                        <UiButton v-if="licence.active" size="xs" variant="ghost" @click="revoke(licence)">Withdraw</UiButton>
                        <UiButton v-else size="xs" variant="ghost" @click="restore(licence)">Restore</UiButton>
                    </div>
                </div>
                <ul v-if="licence.activations.length" class="mt-3 divide-y rounded-[var(--radius-control)] border text-sm" style="border-color: var(--border-subtle)">
                    <li v-for="site in licence.activations" :key="site.id" class="flex items-center justify-between gap-2 px-3 py-2">
                        <span>{{ site.domain }} <span class="text-xs" style="color: var(--text-muted)">· last checked {{ site.lastSeen }}<template v-if="site.version"> · {{ site.version }}</template></span></span>
                        <UiButton v-if="mayManage" size="xs" variant="ghost" @click="release(licence, site)">Free this place</UiButton>
                    </li>
                </ul>
            </UiCard>

            <UiCard v-if="subscriptions.length">
                <h2 class="text-base font-semibold">Plans it started</h2>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="subscription in subscriptions" :key="subscription.id">
                        <Link href="/admin/subscriptions" class="hover:underline">{{ subscription.name }}</Link>
                        <span class="text-xs" style="color: var(--text-muted)"> · {{ subscription.status }}<template v-if="subscription.renewsOn"> · renews {{ subscription.renewsOn }}</template></span>
                    </li>
                </ul>
            </UiCard>
        </div>

        <UiModal :open="cancelling" :title="`Cancel ${order.number}`" @close="cancelling = false">
            <form id="cancel-form" class="space-y-3" @submit.prevent="cancel">
                <p class="text-sm" style="color: var(--text-muted)">Its payment request and any invoice issued for it are cancelled too, and any code it held is free again.</p>
                <UiFormField label="Why" required :error="cancelForm.errors.reason">
                    <UiInput v-model="cancelForm.reason" maxlength="300" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="cancelling = false">Keep it</UiButton>
                <UiButton type="submit" form="cancel-form" :loading="cancelForm.processing">Cancel the order</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
