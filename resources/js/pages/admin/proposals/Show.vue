<script setup>
/**
 * Pricing a proposal and sending it.
 *
 * Line items are editable only while the proposal is a draft. Changing a price
 * after somebody has read it is how a disagreement starts, so a sent proposal
 * is revised into a new version instead.
 */
import { ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Pencil, Send, Copy, Ban } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    proposal: { type: Object, required: true },
    quotation: { type: Object, default: null },
    defaultTaxRate: { type: Number, default: 18 },
});

const itemOpen = ref(false);
const editingItem = ref(null);

const itemForm = useForm({
    description: '',
    hsn_sac: '',
    quantity: 1,
    unit: '',
    unit_price: null,
    tax_rate: props.defaultTaxRate,
});

const quotationForm = useForm({
    discount: props.quotation?.discountValue ?? 0,
    notes: props.quotation?.notes ?? '',
});

function openItem(item = null) {
    editingItem.value = item;
    itemForm.clearErrors();
    itemForm.description = item?.description ?? '';
    itemForm.hsn_sac = item?.hsnSac ?? '';
    itemForm.quantity = item?.quantity ?? 1;
    itemForm.unit = item?.unit ?? '';
    itemForm.unit_price = item?.unitPrice ?? null;
    itemForm.tax_rate = item?.taxRate ?? props.defaultTaxRate;
    itemOpen.value = true;
}

function saveItem() {
    const done = { preserveScroll: true, onSuccess: () => closeItem() };

    editingItem.value
        ? itemForm.put(`/admin/proposals/${props.proposal.id}/items/${editingItem.value.id}`, done)
        : itemForm.post(`/admin/proposals/${props.proposal.id}/items`, done);
}

function closeItem() {
    itemOpen.value = false;
    editingItem.value = null;
    itemForm.reset();
}

function deleteItem(item) {
    if (confirm(`Remove "${item.description}"?`)) {
        router.delete(`/admin/proposals/${props.proposal.id}/items/${item.id}`, { preserveScroll: true });
    }
}

function saveQuotation() {
    quotationForm.put(`/admin/proposals/${props.proposal.id}/quotation`, { preserveScroll: true });
}

function send() {
    if (confirm(`Send this to ${props.proposal.client.name}? It cannot be edited afterwards.`)) {
        router.post(`/admin/proposals/${props.proposal.id}/send`, {}, { preserveScroll: true });
    }
}

function revise() {
    router.post(`/admin/proposals/${props.proposal.id}/revise`);
}

function withdraw() {
    if (confirm('Withdraw this proposal? The client will no longer be able to respond.')) {
        router.post(`/admin/proposals/${props.proposal.id}/withdraw`, {}, { preserveScroll: true });
    }
}

const tones = { accepted: 'success', rejected: 'danger', sent: 'brand', draft: 'neutral', withdrawn: 'neutral' };
</script>

<template>
    <Head :title="proposal.title" />

    <AppLayout
        :title="proposal.title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Proposals', href: '/admin/proposals' },
            { label: proposal.number },
        ]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="proposal.title" :description="proposal.summary">
                <template #actions>
                    <UiButton href="/admin/proposals" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton v-if="proposal.editable" size="sm" @click="send">
                        <template #leading><Send class="h-3.5 w-3.5" /></template>
                        Send to the client
                    </UiButton>
                    <UiButton v-else variant="secondary" size="sm" @click="revise">
                        <template #leading><Copy class="h-3.5 w-3.5" /></template>
                        Start a new version
                    </UiButton>
                    <UiButton v-if="proposal.status === 'sent'" variant="ghost" size="sm" @click="withdraw">
                        <template #leading><Ban class="h-3.5 w-3.5" /></template>
                        Withdraw
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard padding="p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <UiBadge :tone="tones[proposal.status] ?? 'neutral'" size="sm" dot>{{ proposal.status }}</UiBadge>
                    <UiBadge v-if="proposal.version > 1" size="sm">version {{ proposal.version }}</UiBadge>
                    <Link
                        :href="`/admin/clients/${proposal.client.id}`"
                        class="text-sm hover:underline"
                        style="color: var(--color-brand-600)"
                    >
                        {{ proposal.client.name }}
                    </Link>
                    <span class="text-xs" style="color: var(--text-muted)">{{ proposal.number }}</span>
                    <span v-if="proposal.validUntil" class="text-xs" style="color: var(--text-muted)">
                        Valid until {{ proposal.validUntil }}
                    </span>
                </div>

                <p v-if="proposal.respondedAt" class="mt-3 text-sm">
                    The client <strong>{{ proposal.status }}</strong> this on {{ proposal.respondedAt }}
                    <span v-if="proposal.respondedIp" style="color: var(--text-muted)"> from {{ proposal.respondedIp }}</span>.
                </p>
                <p v-if="proposal.responseNote" class="mt-1 whitespace-pre-line text-sm" style="color: var(--text-muted)">
                    “{{ proposal.responseNote }}”
                </p>
            </UiCard>

            <!-- ------------------------------------------------- quotation -->
            <UiCard v-if="quotation">
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">Quotation {{ quotation.number }}</h2>
                        <UiButton v-if="proposal.editable" size="xs" @click="openItem()">
                            <template #leading><Plus class="h-3 w-3" /></template>
                            Add a line
                        </UiButton>
                    </div>
                </template>

                <UiEmptyState
                    v-if="!quotation.items.length"
                    title="No line items yet"
                    description="A proposal cannot be sent without at least one."
                />

                <div v-else class="-mx-5 overflow-x-auto scrollbar-thin sm:mx-0">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wide"
                                style="border-color: var(--border-subtle); color: var(--text-muted)">
                                <th class="px-5 py-2 font-medium sm:px-0">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Qty</th>
                                <th class="px-3 py-2 text-right font-medium">Rate</th>
                                <th class="px-3 py-2 text-right font-medium">Tax</th>
                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                                <th v-if="proposal.editable" class="px-5 py-2 sm:px-0" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in quotation.items" :key="item.id" class="border-b last:border-0"
                                style="border-color: var(--border-subtle)">
                                <td class="px-5 py-2.5 sm:px-0">
                                    {{ item.description }}
                                    <span v-if="item.hsnSac" class="block text-xs" style="color: var(--text-muted)">
                                        HSN/SAC {{ item.hsnSac }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.quantity }} {{ item.unit || '' }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.unitPriceLabel }}</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.taxRate }}%</td>
                                <td class="px-3 py-2.5 text-right tnum">{{ item.amount }}</td>
                                <td v-if="proposal.editable" class="px-5 py-2.5 text-right sm:px-0">
                                    <span class="flex justify-end gap-0.5">
                                        <UiButton variant="ghost" size="xs" icon aria-label="Edit line" @click="openItem(item)">
                                            <Pencil class="h-3 w-3" />
                                        </UiButton>
                                        <UiButton variant="ghost" size="xs" icon aria-label="Remove line" @click="deleteItem(item)">
                                            <Trash2 class="h-3 w-3" style="color: var(--color-danger-500)" />
                                        </UiButton>
                                    </span>
                                </td>
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
                        <dt style="color: var(--text-muted)">Discount</dt>
                        <dd class="tnum">{{ quotation.discount }}</dd>
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

                <form v-if="proposal.editable" class="mt-5 border-t pt-4" style="border-color: var(--border-subtle)" @submit.prevent="saveQuotation">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <UiFormField label="Discount (₹)" :error="quotationForm.errors.discount">
                            <UiInput v-model="quotationForm.discount" type="number" step="0.01" min="0" />
                        </UiFormField>

                        <UiFormField label="Notes on the quotation" :error="quotationForm.errors.notes" class="sm:col-span-2">
                            <UiTextarea v-model="quotationForm.notes" :rows="2" />
                        </UiFormField>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <UiButton type="submit" size="sm" variant="secondary" :loading="quotationForm.processing">
                            Save quotation
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <!-- ------------------------------------------------- the words -->
            <UiCard v-if="proposal.body">
                <template #header><h2 class="text-base font-semibold">The proposal</h2></template>
                <div class="whitespace-pre-line text-sm leading-relaxed">{{ proposal.body }}</div>
            </UiCard>

            <div class="grid gap-5 sm:grid-cols-2">
                <UiCard v-if="proposal.deliverables.length">
                    <template #header><h2 class="text-base font-semibold">Deliverables</h2></template>
                    <ul class="space-y-1.5 text-sm">
                        <li v-for="(item, index) in proposal.deliverables" :key="index">· {{ item }}</li>
                    </ul>
                </UiCard>

                <UiCard v-if="proposal.assumptions.length">
                    <template #header><h2 class="text-base font-semibold">Assumptions</h2></template>
                    <ul class="space-y-1.5 text-sm" style="color: var(--text-muted)">
                        <li v-for="(item, index) in proposal.assumptions" :key="index">· {{ item }}</li>
                    </ul>
                </UiCard>
            </div>
        </div>

        <UiModal :open="itemOpen" :title="editingItem ? 'Edit line item' : 'Add a line item'" size="lg" @close="closeItem">
            <form id="item-form" class="space-y-4" @submit.prevent="saveItem">
                <UiFormField label="Description" required :error="itemForm.errors.description">
                    <UiInput v-model="itemForm.description" placeholder="Design and build, phase one" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Quantity" required :error="itemForm.errors.quantity">
                        <UiInput v-model="itemForm.quantity" type="number" step="0.01" min="0.01" />
                    </UiFormField>

                    <UiFormField label="Unit" :error="itemForm.errors.unit" hint="Optional: hours, months, licences.">
                        <UiInput v-model="itemForm.unit" />
                    </UiFormField>

                    <UiFormField label="Rate (₹)" required :error="itemForm.errors.unit_price">
                        <UiInput v-model="itemForm.unit_price" type="number" step="0.01" min="0" />
                    </UiFormField>

                    <UiFormField label="Tax rate (%)" required :error="itemForm.errors.tax_rate">
                        <UiInput v-model="itemForm.tax_rate" type="number" step="0.01" min="0" max="100" />
                    </UiFormField>

                    <UiFormField label="HSN / SAC" :error="itemForm.errors.hsn_sac" hint="998314 for IT services.">
                        <UiInput v-model="itemForm.hsn_sac" />
                    </UiFormField>
                </div>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="closeItem">Cancel</UiButton>
                <UiButton type="submit" form="item-form" :loading="itemForm.processing">
                    {{ editingItem ? 'Save line' : 'Add line' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
