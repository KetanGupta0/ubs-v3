<script setup>
/**
 * One order (CL5): what was in it, what it cost with GST, its invoice, and for
 * source code the licence (CL4).
 *
 * A licence key is made the first time somebody asks and shown that once,
 * like an API key; a lost one is replaced, and the old one stops working. The
 * sites using it can be freed, and every version inside the update window can
 * be downloaded through a link that expires.
 */
import { ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Copy, CreditCard, Download, FileText, Globe, KeyRound, RefreshCw, ShieldAlert, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import { toast } from '@/support/toast';

const props = defineProps({
    order: { type: Object, required: true },
    licences: { type: Array, default: () => [] },
    mayHandleLicences: { type: Boolean, default: false },
});

const page = usePage();
const tone = { paid: 'success', pending: 'warning', cancelled: 'neutral', refunded: 'neutral', partially_refunded: 'info' };

/* The one readable copy of a key arrives as a flash payload. */
const issued = ref(null);
watch(() => page.props.flash?.issuedLicenceKey, (value) => {
    if (value) issued.value = value;
}, { immediate: true });

async function copyKey() {
    try {
        await navigator.clipboard.writeText(issued.value.key);
        toast.success('Key copied. Keep it somewhere safe: it is not shown again.');
    } catch {
        toast.error('Could not copy. Select the key and copy it by hand.');
    }
}

function makeKey(licence) {
    router.post(`/client/licences/${licence.id}/key`, {}, { preserveScroll: true });
}

function rotateKey(licence) {
    if (window.confirm('Make a new key? The old one stops working at once, so every site using it needs the new one.')) {
        router.post(`/client/licences/${licence.id}/key/rotate`, {}, { preserveScroll: true });
    }
}

function release(licence, site) {
    if (window.confirm(`Free the place ${site.domain} holds? If it checks in again it takes a place again.`)) {
        router.delete(`/client/licences/${licence.id}/sites/${site.id}`, { preserveScroll: true });
    }
}

function download(licence, version) {
    router.post(`/client/licences/${licence.id}/versions/${version.id}/download`, {}, { preserveScroll: true });
}

function cancel() {
    if (window.confirm(`Cancel order ${props.order.number}? Nothing has been charged.`)) {
        router.post(`/client/orders/${props.order.id}/cancel`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`Order ${order.number}`" />

    <AppLayout :title="`Order ${order.number}`" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Orders', href: '/client/orders' }, { label: order.number }]">
        <div class="space-y-5">
            <PageHeader :title="`Order ${order.number}`" :description="`Placed ${order.placedAt}${order.placedBy ? ` by ${order.placedBy}` : ''}.`">
                <template #actions>
                    <UiButton href="/client/orders" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Orders
                    </UiButton>
                </template>
            </PageHeader>

            <!-- A key, shown once. -->
            <UiCard v-if="issued" class="border-2" style="border-color: var(--color-brand-500)">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <KeyRound class="h-4 w-4" />
                    {{ issued.rotated ? 'Your new licence key' : 'Your licence key' }} for {{ issued.product }}
                </p>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">
                    Copy it now and keep it somewhere safe. Only a fingerprint of it is kept here, so it cannot be shown again.<template v-if="issued.rotated"> The old key has stopped working.</template>
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <code class="select-all break-all rounded-[var(--radius-control)] px-3 py-2 font-mono text-sm tracking-wide" style="background: var(--surface-sunken)">{{ issued.key }}</code>
                    <UiButton size="sm" variant="secondary" @click="copyKey">
                        <template #leading><Copy class="h-3.5 w-3.5" /></template>
                        Copy
                    </UiButton>
                    <UiButton size="sm" variant="ghost" icon aria-label="Hide the key" @click="issued = null"><X class="h-3.5 w-3.5" /></UiButton>
                </div>
            </UiCard>

            <UiCard>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <UiBadge :tone="tone[order.status] ?? 'neutral'" dot>{{ order.statusLabel }}</UiBadge>
                        <p v-if="order.paidAt" class="mt-1 text-xs" style="color: var(--text-muted)">Paid {{ order.paidAt }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <UiButton v-if="order.canPay" size="sm" :href="`/client/payments/${order.paymentId}`">
                            <template #leading><CreditCard class="h-3.5 w-3.5" /></template>
                            Pay {{ order.total }}
                        </UiButton>
                        <UiButton v-if="order.invoice" size="sm" variant="secondary" :href="`/client/invoices/${order.invoice.id}/pdf`" :inertia="false">
                            <template #leading><FileText class="h-3.5 w-3.5" /></template>
                            Invoice {{ order.invoice.number }}
                        </UiButton>
                        <UiButton v-if="order.canCancel" size="sm" variant="ghost" @click="cancel">Cancel the order</UiButton>
                    </div>
                </div>

                <ul class="mt-5 divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="line in order.lines" :key="line.description" class="py-3">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ line.description }}</p>
                                <p v-if="line.terms.length" class="text-xs" style="color: var(--text-muted)">{{ line.terms.join(' · ') }}</p>
                            </div>
                            <span class="tnum text-sm">{{ line.amount }}</span>
                        </div>
                        <ul v-if="line.addons.length" class="mt-2 space-y-1 pl-3">
                            <li v-for="addon in line.addons" :key="addon.description" class="flex justify-between gap-4 text-sm" style="color: var(--text-base)">
                                <span>{{ addon.description }}</span>
                                <span class="tnum">{{ addon.amount }}</span>
                            </li>
                        </ul>
                    </li>
                </ul>

                <dl class="ml-auto mt-3 max-w-xs space-y-1.5 border-t pt-3 text-sm" style="border-color: var(--border-subtle)">
                    <div class="flex justify-between"><dt style="color: var(--text-muted)">Subtotal</dt><dd class="tnum">{{ order.subtotal }}</dd></div>
                    <div v-for="coupon in order.coupons" :key="coupon.code" class="flex justify-between"><dt style="color: var(--text-muted)">Code {{ coupon.code }}</dt><dd class="tnum">− {{ coupon.amount }}</dd></div>
                    <div v-if="order.discount" class="flex justify-between"><dt style="color: var(--text-muted)">Taxable value</dt><dd class="tnum">{{ order.taxable }}</dd></div>
                    <div v-for="part in order.breakup" :key="part.label" class="flex justify-between"><dt style="color: var(--text-muted)">{{ part.label }}</dt><dd class="tnum">{{ part.amount }}</dd></div>
                    <div class="flex justify-between font-semibold"><dt>Total</dt><dd class="tnum">{{ order.total }}</dd></div>
                </dl>

                <p class="mt-4 text-xs" style="color: var(--text-muted)">
                    Billed to {{ order.billing.name }}<template v-if="order.billing.gstin">, GSTIN {{ order.billing.gstin }}</template>, {{ order.billing.state }}.
                </p>
            </UiCard>

            <!-- ------------------------------------------------------- licences -->
            <UiCard v-for="licence in licences" :key="licence.id">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <KeyRound class="h-4 w-4" />
                            {{ licence.product }}, {{ licence.tier }} licence
                        </h2>
                        <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                            {{ licence.sites }} · updates until {{ licence.updatesUntil }}<template v-if="licence.supportUntil"> · support until {{ licence.supportUntil }}</template>
                        </p>
                    </div>
                    <UiBadge v-if="!licence.active" tone="danger" dot>Withdrawn</UiBadge>
                    <UiBadge v-else-if="!licence.inUpdateWindow" tone="neutral" dot>Updates ended</UiBadge>
                    <UiBadge v-else tone="success" dot>Active</UiBadge>
                </div>

                <p v-if="!licence.active" class="mt-3 flex items-start gap-2 text-sm">
                    <ShieldAlert class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-danger)" />
                    {{ licence.revokedReason || 'This licence has been withdrawn.' }} The key no longer works, and nothing can be downloaded under it.
                </p>

                <template v-else>
                    <div class="mt-4 rounded-[var(--radius-control)] p-3" style="background: var(--surface-sunken)">
                        <p class="text-xs font-medium" style="color: var(--text-muted)">Licence key</p>
                        <div v-if="licence.hasKey" class="mt-1 flex flex-wrap items-center justify-between gap-2">
                            <span class="font-mono text-sm">{{ licence.maskedKey }}</span>
                            <UiButton v-if="mayHandleLicences" size="xs" variant="ghost" @click="rotateKey(licence)">
                                <template #leading><RefreshCw class="h-3 w-3" /></template>
                                Lost it? Make a new one
                            </UiButton>
                        </div>
                        <div v-else class="mt-1 flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm">Not made yet. It is shown once, when it is made.</span>
                            <UiButton v-if="mayHandleLicences" size="xs" @click="makeKey(licence)">Show my key</UiButton>
                        </div>
                        <p v-if="!mayHandleLicences" class="mt-1 text-xs" style="color: var(--text-muted)">Technical people and owners in your organisation, and whoever bought it, use the key and the downloads.</p>
                    </div>

                    <div v-if="mayHandleLicences" class="mt-5">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Globe class="h-4 w-4" />
                            Sites using it
                            <span class="font-normal tnum" style="color: var(--text-muted)">{{ licence.sitesInUse }}<template v-if="licence.sitesLimit"> of {{ licence.sitesLimit }}</template></span>
                        </h3>
                        <p v-if="!licence.activations.length" class="mt-1 text-xs" style="color: var(--text-muted)">None yet. A site takes a place the first time the software checks its key.</p>
                        <ul v-else class="mt-2 divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                            <li v-for="site in licence.activations" :key="site.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span>
                                    <span class="font-medium">{{ site.domain }}</span>
                                    <span class="text-xs" style="color: var(--text-muted)"> · since {{ site.since }} · last checked {{ site.lastSeen }}<template v-if="site.version"> · version {{ site.version }}</template></span>
                                </span>
                                <UiButton size="xs" variant="ghost" @click="release(licence, site)">Free this place</UiButton>
                            </li>
                        </ul>
                    </div>

                    <div v-if="mayHandleLicences" class="mt-5">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Download class="h-4 w-4" />
                            Download the code
                        </h3>
                        <p v-if="!licence.versions.length" class="mt-1 text-xs" style="color: var(--text-muted)">Nothing to download yet. We will tell you when it is ready.</p>
                        <ul v-else class="mt-2 divide-y rounded-[var(--radius-control)] border" style="border-color: var(--border-subtle)">
                            <li v-for="(version, index) in licence.versions" :key="version.id" class="px-3 py-2.5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm">
                                        <span class="font-medium">Version {{ version.version }}</span>
                                        <UiBadge v-if="index === 0" class="ml-1" tone="info" size="sm">Latest</UiBadge>
                                        <span class="text-xs" style="color: var(--text-muted)"> · {{ version.releasedOn }} · {{ version.size }}</span>
                                    </span>
                                    <UiButton size="xs" variant="secondary" :disabled="version.downloadsLeft === 0" @click="download(licence, version)">
                                        <template #leading><Download class="h-3 w-3" /></template>
                                        Download
                                    </UiButton>
                                </div>
                                <p v-if="version.changelog" class="mt-1 whitespace-pre-line text-xs" style="color: var(--text-base)">{{ version.changelog }}</p>
                                <p class="mt-1 break-all font-mono text-[11px]" style="color: var(--text-muted)">SHA-256 {{ version.sha256 }}</p>
                                <p class="text-[11px]" style="color: var(--text-muted)">{{ version.downloadsLeft }} {{ version.downloadsLeft === 1 ? 'download' : 'downloads' }} left of this version</p>
                            </li>
                        </ul>
                        <p v-if="!licence.inUpdateWindow" class="mt-2 text-xs" style="color: var(--text-muted)">Updates ended on {{ licence.updatesUntil }}. Versions released before then stay yours to download.</p>
                    </div>
                </template>
            </UiCard>
        </div>
    </AppLayout>
</template>
