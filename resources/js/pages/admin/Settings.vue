<script setup>
/**
 * Company details, invoice numbering and the wording of automated messages.
 *
 * No provider credential is editable here, on purpose. A key typed into a
 * settings screen ends up in a database backup and one click from being shown
 * on a monitor; the environment is where it belongs. This page only says
 * whether each provider is wired up.
 */
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, X, Mail, MessageSquare, Info } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTabs from '@/components/UI/UiTabs.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    company: { type: Object, required: true },
    invoicing: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    providers: { type: Array, default: () => [] },
});

const tab = ref('company');

const tabs = [
    { value: 'company', label: 'Company' },
    { value: 'invoicing', label: 'Invoicing' },
    { value: 'templates', label: 'Messages' },
    { value: 'providers', label: 'Providers' },
];

const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
].map((label, index) => ({ value: index + 1, label }));

const companyForm = useForm({ ...props.company });
const invoicingForm = useForm({ ...props.invoicing });

/*
 * One form per template, keyed by id, so saving the welcome email does not
 * quietly resubmit an unrelated SMS somebody else was halfway through editing.
 */
const templateForms = Object.fromEntries(
    props.templates.map((template) => [
        template.id,
        useForm({
            subject: template.subject ?? '',
            body: template.body,
            is_active: template.isActive,
        }),
    ]),
);

/* Written out rather than interpolated, because a literal }} would close the
 * interpolation it sits in. */
const placeholder = (variable) => `{${'{'}${variable}}${'}'}`;

const nextInvoiceNumber = computed(
    () => `${invoicingForm.prefix}-${String(invoicingForm.next_number).padStart(4, '0')}`,
);

function saveCompany() {
    companyForm.put('/admin/settings/company', { preserveScroll: true });
}

function saveInvoicing() {
    invoicingForm.put('/admin/settings/invoicing', { preserveScroll: true });
}

function saveTemplate(template) {
    templateForms[template.id].put(`/admin/settings/templates/${template.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Settings" />

    <AppLayout title="Settings" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Settings' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader
                title="Settings"
                description="The details that appear on invoices and in automated messages."
            />

            <UiTabs v-model="tab" :tabs="tabs" class="mb-5" />

            <!-- --------------------------------------------------- company -->
            <form v-if="tab === 'company'" class="space-y-5" @submit.prevent="saveCompany">
                <FormSection title="Who we are" description="Used on the public site, in invoices and in email footers.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Trading name" required :error="companyForm.errors.name">
                            <UiInput v-model="companyForm.name" />
                        </UiFormField>

                        <UiFormField label="Legal name" required :error="companyForm.errors.legal_name">
                            <UiInput v-model="companyForm.legal_name" />
                        </UiFormField>

                        <UiFormField label="Email" :error="companyForm.errors.email">
                            <UiInput v-model="companyForm.email" type="email" />
                        </UiFormField>

                        <UiFormField label="Phone" :error="companyForm.errors.phone">
                            <UiInput v-model="companyForm.phone" type="tel" />
                        </UiFormField>

                        <UiFormField label="Address" :error="companyForm.errors.address" class="sm:col-span-2">
                            <UiTextarea v-model="companyForm.address" :rows="3" />
                        </UiFormField>

                        <UiFormField label="City" :error="companyForm.errors.city">
                            <UiInput v-model="companyForm.city" />
                        </UiFormField>

                        <UiFormField label="State" :error="companyForm.errors.state">
                            <UiInput v-model="companyForm.state" />
                        </UiFormField>

                        <UiFormField label="Postal code" :error="companyForm.errors.postal_code">
                            <UiInput v-model="companyForm.postal_code" />
                        </UiFormField>
                    </div>
                </FormSection>

                <FormSection title="Registration" description="Printed on invoices where they apply.">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <UiFormField label="GSTIN" :error="companyForm.errors.gstin">
                            <UiInput v-model="companyForm.gstin" />
                        </UiFormField>

                        <UiFormField label="CIN" :error="companyForm.errors.cin">
                            <UiInput v-model="companyForm.cin" />
                        </UiFormField>

                        <UiFormField label="PAN" :error="companyForm.errors.pan">
                            <UiInput v-model="companyForm.pan" />
                        </UiFormField>
                    </div>
                </FormSection>

                <div class="flex justify-end pb-safe">
                    <UiButton type="submit" :loading="companyForm.processing">Save company details</UiButton>
                </div>
            </form>

            <!-- ------------------------------------------------- invoicing -->
            <form v-else-if="tab === 'invoicing'" class="space-y-5" @submit.prevent="saveInvoicing">
                <FormSection title="Invoice numbering" description="Numbers run in a single sequence, so nothing is ever issued twice.">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Prefix" required :error="invoicingForm.errors.prefix">
                            <UiInput v-model="invoicingForm.prefix" />
                        </UiFormField>

                        <UiFormField label="Next number" required :error="invoicingForm.errors.next_number">
                            <UiInput v-model="invoicingForm.next_number" type="number" min="1" />
                        </UiFormField>
                    </div>

                    <p class="text-sm" style="color: var(--text-muted)">
                        The next invoice will be numbered
                        <span class="font-semibold tnum" style="color: var(--text-strong)">{{ nextInvoiceNumber }}</span>.
                    </p>
                </FormSection>

                <FormSection title="Tax and terms">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField
                            label="Financial year starts in"
                            required
                            :error="invoicingForm.errors.financial_year_start_month"
                        >
                            <UiSelect v-model="invoicingForm.financial_year_start_month" :options="months" />
                        </UiFormField>

                        <UiFormField label="Default tax rate (%)" required :error="invoicingForm.errors.default_tax_rate">
                            <UiInput v-model="invoicingForm.default_tax_rate" type="number" step="0.01" min="0" max="100" />
                        </UiFormField>
                    </div>

                    <UiFormField
                        label="Payment terms"
                        :error="invoicingForm.errors.terms"
                        hint="Printed at the bottom of every invoice."
                    >
                        <UiTextarea v-model="invoicingForm.terms" :rows="4" />
                    </UiFormField>
                </FormSection>

                <div class="flex justify-end pb-safe">
                    <UiButton type="submit" :loading="invoicingForm.processing">Save invoicing</UiButton>
                </div>
            </form>

            <!-- ------------------------------------------------- templates -->
            <div v-else-if="tab === 'templates'" class="space-y-5">
                <p
                    class="flex items-start gap-2 rounded-[var(--radius-card)] border px-4 py-3 text-sm"
                    style="border-color: var(--border-subtle); color: var(--text-muted)"
                >
                    <Info class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>
                        Anything in double braces is filled in when the message goes out. Leave a placeholder alone
                        unless you also remove every mention of it.
                    </span>
                </p>

                <FormSection
                    v-for="template in templates"
                    :key="template.id"
                    :title="template.name"
                    :description="template.channel === 'mail' ? 'Sent by email' : 'Sent by SMS'"
                >
                    <form class="space-y-4" @submit.prevent="saveTemplate(template)">
                        <UiFormField
                            v-if="template.channel === 'mail'"
                            label="Subject"
                            required
                            :error="templateForms[template.id].errors.subject"
                        >
                            <UiInput v-model="templateForms[template.id].subject" />
                        </UiFormField>

                        <UiFormField label="Message" required :error="templateForms[template.id].errors.body">
                            <UiTextarea
                                v-model="templateForms[template.id].body"
                                :rows="template.channel === 'mail' ? 8 : 4"
                            />
                        </UiFormField>

                        <div v-if="template.variables.length" class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs" style="color: var(--text-muted)">Placeholders:</span>
                            <UiBadge v-for="variable in template.variables" :key="variable" size="sm">
                                {{ placeholder(variable) }}
                            </UiBadge>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <UiSwitch
                                v-model="templateForms[template.id].is_active"
                                label="In use"
                                description="Switched off, the built in wording is used instead."
                            />

                            <UiButton
                                type="submit"
                                size="sm"
                                :loading="templateForms[template.id].processing"
                            >
                                <template #leading>
                                    <component :is="template.channel === 'mail' ? Mail : MessageSquare" class="h-3.5 w-3.5" />
                                </template>
                                Save
                            </UiButton>
                        </div>
                    </form>
                </FormSection>
            </div>

            <!-- ------------------------------------------------- providers -->
            <div v-else class="space-y-5">
                <FormSection
                    title="Connected services"
                    description="Keys live in the server environment, never in this database, so there is nothing to type in here."
                >
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="provider in providers"
                            :key="provider.name"
                            class="flex items-center justify-between gap-4 py-3 first:pt-0 last:pb-0"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium">{{ provider.name }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">{{ provider.detail }}</p>
                            </div>

                            <UiBadge :tone="provider.configured ? 'success' : 'warning'" size="sm">
                                <component :is="provider.configured ? Check : X" class="mr-1 h-3 w-3" />
                                {{ provider.configured ? 'Configured' : 'Not configured' }}
                            </UiBadge>
                        </li>
                    </ul>
                </FormSection>

                <p class="text-sm" style="color: var(--text-muted)">
                    A service showing as not configured still works in development: mail and SMS both land in the
                    application log instead of going anywhere.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
