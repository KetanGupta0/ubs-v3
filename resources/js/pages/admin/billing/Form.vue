<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    people: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    defaultTaxRate: { type: Number, default: 18 },
});

const form = useForm({
    user_id: null,
    title: '',
    description: '',
    amount: null,
    tax_rate: props.defaultTaxRate,
    due_on: '',
    notes: '',
    project_id: null,
    milestone_id: null,
    notify: true,
});

const projectsForPerson = computed(() =>
    props.projects.filter((project) => !form.user_id || project.clientId === Number(form.user_id)),
);

const milestones = computed(() => {
    const project = props.projects.find((candidate) => candidate.value === Number(form.project_id));

    return project?.milestones ?? [];
});

// Picking a milestone that carries a price fills the amount in, because typing
// it again is how the invoice ends up disagreeing with the plan.
watch(() => form.milestone_id, (id) => {
    const milestone = milestones.value.find((candidate) => candidate.value === Number(id));

    if (milestone?.amount) {
        form.amount = milestone.amount;

        if (!form.title) {
            form.title = milestone.label;
        }
    }
});

const total = computed(() => {
    const amount = Number(form.amount) || 0;
    const tax = (amount * (Number(form.tax_rate) || 0)) / 100;

    return (amount + tax).toLocaleString('en-IN', { style: 'currency', currency: 'INR' });
});

function submit() {
    form.post('/admin/billing');
}
</script>

<template>
    <Head title="Raise a payment request" />

    <AppLayout
        title="Raise a payment request"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Billing', href: '/admin/billing' },
            { label: 'New' },
        ]"
    >
        <form class="mx-auto max-w-3xl space-y-5" @submit.prevent="submit">
            <PageHeader
                title="Raise a payment request"
                description="The invoice is issued at the same moment, so they have the document before they pay."
            >
                <template #actions>
                    <UiButton href="/admin/billing" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="Who and what for">
                <UiFormField label="Raise it on" required :error="form.errors.user_id">
                    <UiCombobox v-model="form.user_id" :options="people" placeholder="Search clients and students…" />
                </UiFormField>

                <UiFormField label="What it is for" required :error="form.errors.title">
                    <UiInput v-model="form.title" placeholder="Milestone 2: build complete" />
                </UiFormField>

                <UiFormField label="Description" :error="form.errors.description" hint="Shown to them on the payment page.">
                    <UiTextarea v-model="form.description" :rows="3" />
                </UiFormField>

                <div v-if="projectsForPerson.length" class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Against a project" :error="form.errors.project_id">
                        <UiSelect
                            v-model="form.project_id"
                            :options="[{ value: '', label: 'Not tied to one' }, ...projectsForPerson]"
                        />
                    </UiFormField>

                    <UiFormField v-if="milestones.length" label="Against a milestone" :error="form.errors.milestone_id">
                        <UiSelect
                            v-model="form.milestone_id"
                            :options="[{ value: '', label: 'Not tied to one' }, ...milestones]"
                        />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection title="How much">
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Amount before tax (₹)" required :error="form.errors.amount">
                        <UiInput v-model="form.amount" type="number" step="0.01" min="1" />
                    </UiFormField>

                    <UiFormField label="Tax rate (%)" required :error="form.errors.tax_rate">
                        <UiInput v-model="form.tax_rate" type="number" step="0.01" min="0" max="100" />
                    </UiFormField>

                    <UiFormField label="Due by" :error="form.errors.due_on">
                        <UiDateInput v-model="form.due_on" />
                    </UiFormField>
                </div>

                <p class="text-sm" style="color: var(--text-muted)">
                    They will be asked for <strong class="tnum" style="color: var(--text-strong)">{{ total }}</strong>.
                    The tax split between CGST, SGST and IGST is worked out from their state.
                </p>

                <UiFormField label="Internal notes" :error="form.errors.notes" hint="Not shown to them.">
                    <UiTextarea v-model="form.notes" :rows="2" />
                </UiFormField>

                <UiSwitch
                    v-model="form.notify"
                    label="Tell them now"
                    description="Sends the request by email, and by SMS if we have a number."
                />
            </FormSection>

            <div class="flex justify-end pb-safe">
                <UiButton type="submit" :loading="form.processing">Raise the request</UiButton>
            </div>
        </form>
    </AppLayout>
</template>
