<script setup>
/** Write or change a draft agreement. Once sent it is fixed; changes are a new version. */
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    agreement: { type: Object, default: null },
    kinds: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    contracts: { type: Array, default: () => [] },
    subscriptions: { type: Array, default: () => [] },
    preselectedClient: { type: Number, default: null },
});

const form = useForm({
    client_id: props.agreement?.client_id ?? props.preselectedClient,
    kind: props.agreement?.kind ?? 'sow',
    title: props.agreement?.title ?? '',
    body: props.agreement?.body ?? '',
    project_id: props.agreement?.project_id ?? '',
    maintenance_contract_id: props.agreement?.maintenance_contract_id ?? '',
    subscription_id: props.agreement?.subscription_id ?? '',
    effective_on: props.agreement?.effective_on ?? '',
    expires_on: props.agreement?.expires_on ?? '',
});

const forClient = (list, none) => computed(() => [{ value: '', label: none }, ...list.filter((item) => item.clientId === form.client_id)]);
const projectOptions = forClient(props.projects, 'Not tied to a project');
const contractOptions = forClient(props.contracts, 'Not tied to a contract');
const subscriptionOptions = forClient(props.subscriptions, 'Not tied to a subscription');

// Links to another client's records are not choices once the client changes.
watch(() => form.client_id, () => {
    for (const [field, options] of [['project_id', projectOptions], ['maintenance_contract_id', contractOptions], ['subscription_id', subscriptionOptions]]) {
        if (form[field] && !options.value.some((option) => String(option.value) === String(form[field]))) form[field] = '';
    }
});

function save() {
    const payload = (data) => ({
        ...data,
        project_id: data.project_id || null,
        maintenance_contract_id: data.maintenance_contract_id || null,
        subscription_id: data.subscription_id || null,
        effective_on: data.effective_on || null,
        expires_on: data.expires_on || null,
    });

    props.agreement
        ? form.transform(payload).put(`/admin/agreements/${props.agreement.id}`)
        : form.transform(payload).post('/admin/agreements');
}
</script>

<template>
    <Head :title="agreement ? `Edit ${agreement.number}` : 'New agreement'" />

    <AppLayout
        :title="agreement ? agreement.number : 'New agreement'"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Agreements', href: '/admin/agreements' }, { label: agreement ? agreement.number : 'New' }]"
    >
        <div>
            <PageHeader :title="agreement ? `Draft ${agreement.number}` : 'New agreement'" description="A draft. Nothing reaches the client until you send it, and after that the text is fixed.">
                <template #actions>
                    <UiButton :href="agreement ? `/admin/agreements/${agreement.id}` : '/admin/agreements'" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <form class="space-y-5" @submit.prevent="save">
                <FormSection title="Who and what">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Client" required :error="form.errors.client_id">
                            <UiCombobox v-model="form.client_id" :options="clients" placeholder="Choose the organisation" />
                        </UiFormField>
                        <UiFormField label="Kind" :error="form.errors.kind">
                            <UiSelect v-model="form.kind" :options="kinds" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Title" required :error="form.errors.title">
                        <UiInput v-model="form.title" placeholder="Statement of work: warehouse system, phase two" />
                    </UiFormField>
                </FormSection>

                <FormSection title="The text" description="Markdown: ## headings, numbered and bulleted lists, **bold**. Typed HTML is removed.">
                    <UiFormField label="Agreement" required :error="form.errors.body">
                        <UiTextarea v-model="form.body" :rows="18" class="font-mono text-xs" />
                    </UiFormField>
                </FormSection>

                <FormSection title="What it covers">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <UiFormField label="Project" :error="form.errors.project_id">
                            <UiSelect v-model="form.project_id" :options="projectOptions" :disabled="!form.client_id" />
                        </UiFormField>
                        <UiFormField label="Maintenance contract" :error="form.errors.maintenance_contract_id">
                            <UiSelect v-model="form.maintenance_contract_id" :options="contractOptions" :disabled="!form.client_id" />
                        </UiFormField>
                        <UiFormField label="Subscription" :error="form.errors.subscription_id">
                            <UiSelect v-model="form.subscription_id" :options="subscriptionOptions" :disabled="!form.client_id" />
                        </UiFormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Effective from" :error="form.errors.effective_on">
                            <UiInput v-model="form.effective_on" type="date" />
                        </UiFormField>
                        <UiFormField label="Ends on" hint="Empty for an agreement with no end date." :error="form.errors.expires_on">
                            <UiInput v-model="form.expires_on" type="date" />
                        </UiFormField>
                    </div>
                </FormSection>

                <div class="flex justify-end">
                    <UiButton type="submit" :loading="form.processing">Save the draft</UiButton>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
