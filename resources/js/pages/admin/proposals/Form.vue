<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';

defineProps({
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const form = useForm({
    client_id: null,
    project_id: null,
    title: '',
    summary: '',
    body: '',
    assumptions: [],
    deliverables: [],
    timeline: '',
    valid_until: '',
});

function submit() {
    form.post('/admin/proposals');
}
</script>

<template>
    <Head title="New proposal" />

    <AppLayout
        title="New proposal"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Proposals', href: '/admin/proposals' },
            { label: 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader
                title="New proposal"
                description="Write it here, price it on the next screen, then send it."
            >
                <template #actions>
                    <UiButton href="/admin/proposals" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="Who and what">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Client" required :error="form.errors.client_id" class="sm:col-span-2">
                        <UiCombobox v-model="form.client_id" :options="clients" placeholder="Search clients…" />
                    </UiFormField>

                    <UiFormField label="Title" required :error="form.errors.title" class="sm:col-span-2">
                        <UiInput v-model="form.title" placeholder="Warehouse management system, phase one" />
                    </UiFormField>

                    <UiFormField label="Against a project" :error="form.errors.project_id">
                        <UiSelect
                            v-model="form.project_id"
                            :options="[
                                { value: '', label: 'Not yet a project' },
                                ...projects.filter((p) => !form.client_id || p.clientId === Number(form.client_id)),
                            ]"
                        />
                    </UiFormField>

                    <UiFormField
                        label="Valid until"
                        :error="form.errors.valid_until"
                        hint="After this, the client can no longer accept it."
                    >
                        <UiDateInput v-model="form.valid_until" />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection title="The proposal">
                <UiFormField label="Summary" :error="form.errors.summary" hint="One paragraph, shown at the top.">
                    <UiTextarea v-model="form.summary" :rows="3" />
                </UiFormField>

                <UiFormField label="The body" :error="form.errors.body" hint="The approach, in full.">
                    <UiTextarea v-model="form.body" :rows="12" />
                </UiFormField>

                <UiFormField label="Timeline" :error="form.errors.timeline">
                    <UiInput v-model="form.timeline" placeholder="Ten to twelve weeks from sign off" />
                </UiFormField>
            </FormSection>

            <FormSection
                title="Scope"
                description="What they get, and what we have assumed. The assumptions matter more than they look: they are what a disagreement is measured against later."
            >
                <UiFormField label="Deliverables" :error="form.errors.deliverables">
                    <UiRepeater v-model="form.deliverables" placeholder="Add a deliverable" />
                </UiFormField>

                <UiFormField label="Assumptions" :error="form.errors.assumptions">
                    <UiRepeater v-model="form.assumptions" placeholder="Add an assumption" />
                </UiFormField>
            </FormSection>

            <div class="flex justify-end pb-safe">
                <UiButton type="submit" :loading="form.processing">Create the draft</UiButton>
            </div>
        </form>
    </AppLayout>
</template>
