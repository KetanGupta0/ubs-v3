<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';

const props = defineProps({
    contract: { type: Object, default: null },
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.contract));

const form = useForm({
    client_id: props.contract?.client_id ?? null,
    project_id: props.contract?.project_id ?? null,
    plan: props.contract?.plan ?? 'Standard AMC',
    scope: props.contract?.scope ?? [],
    exclusions: props.contract?.exclusions ?? [],
    starts_on: props.contract?.starts_on ?? '',
    ends_on: props.contract?.ends_on ?? '',
    response_hours: props.contract?.response_hours ?? 24,
    resolution_hours: props.contract?.resolution_hours ?? 72,
    included_tickets: props.contract?.included_tickets ?? null,
    amount: props.contract?.amount ?? null,
    billing_interval: props.contract?.billing_interval ?? 'yearly',
    status: props.contract?.status ?? 'active',
});

const title = computed(() => (isEdit.value ? props.contract.reference : 'New contract'));

function submit() {
    isEdit.value
        ? form.put(`/admin/contracts/${props.contract.id}`, { preserveScroll: true })
        : form.post('/admin/contracts');
}

function destroy() {
    if (confirm('Remove this contract?')) {
        router.delete(`/admin/contracts/${props.contract.id}`);
    }
}
</script>

<template>
    <Head :title="title" />

    <AppLayout
        :title="title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Contracts', href: '/admin/contracts' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="title">
                <template #actions>
                    <UiButton href="/admin/contracts" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="Who and what">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Client" required :error="form.errors.client_id">
                        <UiCombobox v-model="form.client_id" :options="clients" placeholder="Search clients…" />
                    </UiFormField>

                    <UiFormField label="Project" :error="form.errors.project_id" hint="If the cover is for one system.">
                        <UiSelect
                            v-model="form.project_id"
                            :options="[
                                { value: '', label: 'Everything we built for them' },
                                ...projects.filter((p) => !form.client_id || p.clientId === Number(form.client_id)),
                            ]"
                        />
                    </UiFormField>

                    <UiFormField label="Plan name" required :error="form.errors.plan">
                        <UiInput v-model="form.plan" placeholder="Standard AMC" />
                    </UiFormField>

                    <UiFormField label="Status" required :error="form.errors.status">
                        <UiSelect
                            v-model="form.status"
                            :options="[
                                { value: 'active', label: 'Active' },
                                { value: 'expired', label: 'Expired' },
                                { value: 'cancelled', label: 'Cancelled' },
                            ]"
                        />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                title="What is covered"
                description="Written plainly, because this is what the client reads when they wonder whether to raise a ticket."
            >
                <UiFormField label="Covered" :error="form.errors.scope">
                    <UiRepeater v-model="form.scope" placeholder="Add something covered" />
                </UiFormField>

                <UiFormField label="Not covered" :error="form.errors.exclusions">
                    <UiRepeater v-model="form.exclusions" placeholder="Add an exclusion" />
                </UiFormField>
            </FormSection>

            <FormSection title="The promise and the price">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Starts" required :error="form.errors.starts_on">
                        <UiDateInput v-model="form.starts_on" />
                    </UiFormField>

                    <UiFormField label="Ends" required :error="form.errors.ends_on">
                        <UiDateInput v-model="form.ends_on" />
                    </UiFormField>

                    <UiFormField
                        label="First response within (hours)"
                        required
                        :error="form.errors.response_hours"
                    >
                        <UiInput v-model="form.response_hours" type="number" min="1" max="720" />
                    </UiFormField>

                    <UiFormField
                        label="Resolution within (hours)"
                        required
                        :error="form.errors.resolution_hours"
                    >
                        <UiInput v-model="form.resolution_hours" type="number" min="1" max="2160" />
                    </UiFormField>

                    <UiFormField
                        label="Tickets included a year"
                        :error="form.errors.included_tickets"
                        hint="Leave blank for unlimited."
                    >
                        <UiInput v-model="form.included_tickets" type="number" min="1" />
                    </UiFormField>

                    <UiFormField label="Amount (₹)" required :error="form.errors.amount">
                        <UiInput v-model="form.amount" type="number" step="0.01" min="0" />
                    </UiFormField>

                    <UiFormField label="Billed" required :error="form.errors.billing_interval">
                        <UiSelect
                            v-model="form.billing_interval"
                            :options="[
                                { value: 'monthly', label: 'Monthly' },
                                { value: 'quarterly', label: 'Quarterly' },
                                { value: 'half_yearly', label: 'Half yearly' },
                                { value: 'yearly', label: 'Yearly' },
                            ]"
                        />
                    </UiFormField>
                </div>
            </FormSection>

            <div class="flex flex-wrap items-center justify-between gap-3 pb-safe">
                <UiButton v-if="isEdit" variant="ghost" size="sm" type="button" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" /></template>
                    Remove
                </UiButton>
                <span v-else />

                <UiButton type="submit" :loading="form.processing">
                    {{ isEdit ? 'Save contract' : 'Create contract' }}
                </UiButton>
            </div>
        </form>
    </AppLayout>
</template>
