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
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    contracts: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    priorities: { type: Array, default: () => [] },
});

const form = useForm({
    subject: '',
    body: '',
    contract_id: props.contracts[0]?.value ?? null,
    project_id: null,
    category: '',
    priority: 'normal',
});

function submit() {
    form.post('/client/tickets');
}
</script>

<template>
    <Head title="Raise a ticket" />

    <AppLayout
        title="Raise a ticket"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Support', href: '/client/support' },
            { label: 'New ticket' },
        ]"
    >
        <form class="mx-auto max-w-2xl space-y-5" @submit.prevent="submit">
            <PageHeader
                title="Raise a ticket"
                description="Tell us what is happening. The more specific the first message, the fewer rounds it takes."
            >
                <template #actions>
                    <UiButton href="/client/support" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="What is wrong">
                <UiFormField label="Subject" required :error="form.errors.subject">
                    <UiInput v-model="form.subject" placeholder="Invoices are not generating for March" />
                </UiFormField>

                <UiFormField
                    label="What is happening"
                    required
                    :error="form.errors.body"
                    hint="What you did, what you expected, and what happened instead."
                >
                    <UiTextarea v-model="form.body" :rows="7" />
                </UiFormField>
            </FormSection>

            <FormSection title="Where and how urgent">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField
                        v-if="contracts.length > 1"
                        label="Against which contract"
                        :error="form.errors.contract_id"
                    >
                        <UiSelect v-model="form.contract_id" :options="contracts" />
                    </UiFormField>

                    <UiFormField v-if="projects.length" label="Project" :error="form.errors.project_id">
                        <UiSelect
                            v-model="form.project_id"
                            :options="[{ value: '', label: 'Not specific to one' }, ...projects]"
                        />
                    </UiFormField>

                    <UiFormField
                        label="Priority"
                        required
                        :error="form.errors.priority"
                        hint="Urgent means the system is unusable right now."
                    >
                        <UiSelect
                            v-model="form.priority"
                            :options="priorities.map((p) => ({ value: p, label: p.charAt(0).toUpperCase() + p.slice(1) }))"
                        />
                    </UiFormField>

                    <UiFormField label="Area" :error="form.errors.category" hint="Optional, for example billing or reports.">
                        <UiInput v-model="form.category" />
                    </UiFormField>
                </div>
            </FormSection>

            <div class="flex justify-end pb-safe">
                <UiButton type="submit" :loading="form.processing">Raise the ticket</UiButton>
            </div>
        </form>
    </AppLayout>
</template>
