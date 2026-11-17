<script setup>
/**
 * Reading a proposal and answering it.
 *
 * Accepting is signing (CL2): a typed name and a code sent to the signer's
 * email, over the PDF that was sent. Declining stays a dialog with an
 * optional reason.
 */
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Clock, Printer, FileDown } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import SignDocument from '@/components/Documents/SignDocument.vue';
import SignatureList from '@/components/Documents/SignatureList.vue';
import { useClientRole } from '@/composables/useClientRole';

const props = defineProps({
    proposal: { type: Object, required: true },
    quotation: { type: Object, default: null },
});

const { may } = useClientRole();

const responding = ref(null);

const form = useForm({ response: '', note: '' });

function open(response) {
    responding.value = response;
    form.clearErrors();
    form.response = response;
    form.note = '';
}

function submit() {
    form.post(`/client/proposals/${props.proposal.id}/respond`, {
        preserveScroll: true,
        onSuccess: () => (responding.value = null),
    });
}

const tones = { accepted: 'success', rejected: 'danger', expired: 'neutral', sent: 'brand' };
</script>

<template>
    <Head :title="proposal.title" />

    <AppLayout
        :title="proposal.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Proposals', href: '/client/proposals' },
            { label: proposal.number },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="proposal.title" :description="proposal.summary">
                <template #actions>
                    <UiButton href="/client/proposals" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton :href="`/client/proposals/${proposal.id}/pdf`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><FileDown class="h-3.5 w-3.5" /></template>
                        PDF
                    </UiButton>
                    <UiButton variant="secondary" size="sm" @click="print()">
                        <template #leading><Printer class="h-3.5 w-3.5" /></template>
                        Print
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2">
                <UiBadge :tone="tones[proposal.status] ?? 'neutral'" size="sm" dot>{{ proposal.statusLabel }}</UiBadge>
                <UiBadge v-if="proposal.version > 1" size="sm">version {{ proposal.version }}</UiBadge>
                <span v-if="proposal.validUntil" class="inline-flex items-center gap-1 text-xs" style="color: var(--text-muted)">
                    <Clock class="h-3 w-3" />
                    {{ proposal.expired ? 'Expired on' : 'Valid until' }} {{ proposal.validUntil }}
                </span>
            </div>

            <!-- ---------------------------------------------- the proposal -->
            <UiCard v-if="proposal.body">
                <div class="whitespace-pre-line text-sm leading-relaxed" style="color: var(--text-base)">
                    {{ proposal.body }}
                </div>
            </UiCard>

            <div class="grid gap-5 sm:grid-cols-2">
                <UiCard v-if="proposal.deliverables.length">
                    <template #header><h2 class="text-base font-semibold">What you get</h2></template>
                    <ul class="space-y-2 text-sm">
                        <li v-for="(item, index) in proposal.deliverables" :key="index" class="flex gap-2">
                            <Check class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-signal-500)" />
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </UiCard>

                <UiCard v-if="proposal.assumptions.length">
                    <template #header><h2 class="text-base font-semibold">What we have assumed</h2></template>
                    <ul class="space-y-2 text-sm" style="color: var(--text-muted)">
                        <li v-for="(item, index) in proposal.assumptions" :key="index" class="flex gap-2">
                            <span aria-hidden="true">·</span>
                            <span>{{ item }}</span>
                        </li>
                    </ul>
                </UiCard>
            </div>

            <UiCard v-if="proposal.timeline">
                <template #header><h2 class="text-base font-semibold">Timeline</h2></template>
                <p class="text-sm">{{ proposal.timeline }}</p>
            </UiCard>

            <!-- --------------------------------------------- the quotation -->
            <UiCard v-if="quotation">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">Quotation</h2>
                        <span class="text-xs" style="color: var(--text-muted)">{{ quotation.number }}</span>
                    </div>
                </template>

                <div class="-mx-5 overflow-x-auto scrollbar-thin sm:mx-0">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wide"
                                style="border-color: var(--border-subtle); color: var(--text-muted)">
                                <th class="px-5 py-2 font-medium sm:px-0">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Qty</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-5 py-2 text-right font-medium sm:px-0">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(item, index) in quotation.items" :key="index" class="border-b last:border-0"
                                style="border-color: var(--border-subtle)">
                                <td class="px-5 py-2.5 sm:px-0">{{ item.description }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.quantity }} {{ item.unit || '' }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.unitPrice }}</td>
                                <td class="px-5 py-2.5 text-right tnum sm:px-0">{{ item.amount }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <dl class="ml-auto mt-4 max-w-xs space-y-1.5 text-sm">
                    <div class="flex justify-between">
                        <dt style="color: var(--text-muted)">Subtotal</dt>
                        <dd class="tnum">{{ quotation.subtotal }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt style="color: var(--text-muted)">Tax</dt>
                        <dd class="tnum">{{ quotation.tax }}</dd>
                    </div>
                    <div class="flex justify-between border-t pt-2 text-base font-semibold"
                         style="border-color: var(--border-strong)">
                        <dt>Total</dt>
                        <dd class="tnum">{{ quotation.total }}</dd>
                    </div>
                </dl>

                <p class="mt-2 text-right text-xs" style="color: var(--text-muted)">{{ quotation.totalInWords }}</p>

                <p v-if="quotation.notes" class="mt-4 whitespace-pre-line text-xs" style="color: var(--text-muted)">
                    {{ quotation.notes }}
                </p>
            </UiCard>

            <!-- ------------------------------------------------ responding -->
            <UiCard v-if="proposal.canRespond && !may('approve')">
                <p class="text-sm" style="color: var(--text-muted)">
                    An authorised signatory or an owner in your organisation accepts or declines proposals. It has been sent to them.
                </p>
            </UiCard>

            <template v-else-if="proposal.canRespond">
                <SignDocument
                    :code-url="`/client/proposals/${proposal.id}/sign/code`"
                    :sign-url="`/client/proposals/${proposal.id}/sign`"
                    :pdf-url="`/client/proposals/${proposal.id}/pdf`"
                    :sha256="proposal.sha256"
                    heading="Accept by signing"
                    intro="Accepting this proposal is signing it, with your name and a code we email you."
                    confirm-label="I have read the proposal and its quotation, and accept them for my organisation."
                    button-label="Accept and sign"
                    with-note
                />
                <p class="text-sm" style="color: var(--text-muted)">
                    If anything needs changing first, reply to the email and we will send a revised version. Or
                    <button type="button" class="font-medium text-danger-500 hover:underline" @click="open('rejected')">decline it</button>.
                </p>
            </template>

            <UiCard v-else-if="proposal.respondedAt">
                <p class="text-sm">
                    You {{ proposal.status }} this on <strong>{{ proposal.respondedAt }}</strong>.
                </p>
                <p v-if="proposal.responseNote" class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">
                    “{{ proposal.responseNote }}”
                </p>
            </UiCard>

            <SignatureList :signatures="proposal.signatures" :signed-url="proposal.hasSignedCopy ? `/client/proposals/${proposal.id}/signed` : null" />
        </div>

        <UiModal
            :open="Boolean(responding)"
            title="Decline this proposal?"
            description="We will come back to you. Telling us why helps us get the next one right."
            @close="responding = null"
        >
            <form id="respond-form" @submit.prevent="submit">
                <UiFormField label="What did not work (optional)" :error="form.errors.note">
                    <UiTextarea v-model="form.note" :rows="4" />
                </UiFormField>

                <p v-if="form.errors.response" class="mt-2 text-sm text-danger-500">{{ form.errors.response }}</p>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="responding = null">Cancel</UiButton>
                <UiButton type="submit" form="respond-form" variant="danger" :loading="form.processing">Decline</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
