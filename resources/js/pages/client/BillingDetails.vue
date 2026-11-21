<script setup>
/**
 * Who we bill (CL14): the organisation's legal name, GSTIN and address.
 * Every invoice copies them when it is issued, so a change applies from
 * now on. The state decides how GST is charged, so it is chosen, not typed.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';

const props = defineProps({
    details: { type: Object, required: true },
    states: { type: Array, default: () => [] },
    canEdit: { type: Boolean, default: false },
});

const form = useForm({ ...props.details });
</script>

<template>
    <Head title="Billing details" />

    <AppLayout title="Billing details" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Payments', href: '/client/payments' }, { label: 'Billing details' }]">
        <div class="space-y-5">
            <PageHeader title="Billing details" description="What we put on your invoices. A change applies to invoices issued from now on; one already issued keeps what it was issued with.">
                <template #actions>
                    <UiButton href="/client/payments" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Payments
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <form class="space-y-4" @submit.prevent="form.put('/client/billing-details', { preserveScroll: true })">
                    <fieldset :disabled="!canEdit" class="space-y-4">
                        <UiFormField label="Legal name" required :error="form.errors.company">
                            <UiInput v-model="form.company" maxlength="160" autocomplete="organization" />
                        </UiFormField>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiFormField label="GSTIN" hint="Leave empty if you are not registered." :error="form.errors.gstin">
                                <UiInput v-model="form.gstin" maxlength="15" class="uppercase" placeholder="23ABCDE1234F1Z5" />
                            </UiFormField>
                            <UiFormField label="State" required hint="Where you are registered. It decides how GST is charged." :error="form.errors.state">
                                <UiSelect v-model="form.state" :options="[{ value: '', label: 'Choose the state' }, ...states]" />
                            </UiFormField>
                        </div>
                        <UiFormField label="Address" :error="form.errors.address_line_1">
                            <UiInput v-model="form.address_line_1" maxlength="255" autocomplete="street-address" />
                        </UiFormField>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <UiFormField label="City" :error="form.errors.city">
                                <UiInput v-model="form.city" maxlength="120" autocomplete="address-level2" />
                            </UiFormField>
                            <UiFormField label="PIN code" :error="form.errors.postal_code">
                                <UiInput v-model="form.postal_code" maxlength="6" inputmode="numeric" autocomplete="postal-code" />
                            </UiFormField>
                        </div>
                    </fieldset>
                    <p v-if="!canEdit" class="text-sm" style="color: var(--text-muted)">Finance or an owner in your organisation keeps these up to date.</p>
                    <div v-else class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing">Save</UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
