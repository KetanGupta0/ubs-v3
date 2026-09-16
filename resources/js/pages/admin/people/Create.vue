<script setup>
/**
 * Creating an account on someone's behalf.
 *
 * The screen says plainly what will happen when it is saved, because the
 * consequence is a message landing in someone's inbox with a live password in
 * it, and an administrator should not discover that afterwards.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Send, Info } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    role: { type: String, required: true },
    roleLabel: { type: String, required: true },
    colleges: { type: Array, default: () => [] },
});

const isStudent = props.role === 'student';

const form = useForm({
    name: '',
    email: '',
    mobile: '',
    company: '',
    designation: '',
    gstin: '',
    city: '',
    state: '',
    college_id: null,
    enrollment_number: '',
    course_of_study: '',
    current_semester: '',
    graduation_year: '',
    note: '',
});

function submit() {
    form.post(`/admin/${props.role}s`);
}
</script>

<template>
    <Head :title="`Add a ${roleLabel.toLowerCase()}`" />

    <AppLayout
        :title="`Add a ${roleLabel.toLowerCase()}`"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: `${roleLabel}s`, href: `/admin/${role}s` },
            { label: 'New' },
        ]"
    >
        <form class="mx-auto max-w-3xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="`Add a ${roleLabel.toLowerCase()}`">
                <template #actions>
                    <UiButton :href="`/admin/${role}s`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Cancel
                    </UiButton>
                </template>
            </PageHeader>

            <div
                class="flex items-start gap-3 rounded-[var(--radius-card)] border p-4"
                style="border-color: var(--color-brand-400); background: color-mix(in oklab, var(--color-brand-500) 7%, transparent)"
            >
                <Info class="mt-0.5 h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                <p class="text-sm" style="color: var(--text-base)">
                    Saving this generates a temporary password and sends it to the email address
                    and, if given, the mobile number. They will be asked to replace it the first
                    time they sign in, and nothing else in their dashboard works until they do.
                </p>
            </div>

            <FormSection title="Who they are">
                <UiFormField label="Full name" required :error="form.errors.name">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.name" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Email" required :error="form.errors.email">
                        <template #default="field">
                            <UiInput
                                :id="field.id"
                                v-model="form.email"
                                type="email"
                                autocapitalize="none"
                                :invalid="field.invalid"
                                required
                            />
                        </template>
                    </UiFormField>

                    <UiFormField
                        label="Mobile"
                        hint="Credentials go here too, which matters if email is missed."
                        :error="form.errors.mobile"
                    >
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.mobile" type="tel" placeholder="98765 43210" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                v-if="!isStudent"
                title="Business details"
                description="Used on proposals, quotations and invoices."
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Company" :error="form.errors.company">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.company" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Their role" :error="form.errors.designation">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.designation" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="GSTIN" :error="form.errors.gstin">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.gstin" class="font-mono" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="City" :error="form.errors.city">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.city" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="State" :error="form.errors.state">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.state" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                v-else
                title="College details"
                description="Needed for internship paperwork. You can fill this in later."
            >
                <UiFormField label="College" :error="form.errors.college_id">
                    <template #default="field">
                        <UiCombobox
                            :id="field.id"
                            v-model="form.college_id"
                            :options="colleges"
                            placeholder="Not linked to a college"
                            :invalid="field.invalid"
                        />
                    </template>
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Enrolment number" :error="form.errors.enrollment_number">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.enrollment_number" class="font-mono" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Course of study" :error="form.errors.course_of_study">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.course_of_study" placeholder="BTech Computer Science" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Current semester" :error="form.errors.current_semester">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.current_semester" type="number" min="1" max="12" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Graduation year" :error="form.errors.graduation_year">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.graduation_year" type="number" min="2000" max="2100" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                title="Welcome message"
                description="Optional. A line that shows a person set this up rather than a script."
            >
                <UiFormField label="Personal note" :error="form.errors.note">
                    <template #default="field">
                        <UiTextarea
                            :id="field.id"
                            v-model="form.note"
                            :rows="2"
                            placeholder="Good speaking with you this morning. Everything for the warehouse project will live here."
                        />
                    </template>
                </UiFormField>
            </FormSection>

            <div class="flex justify-end gap-2">
                <UiButton :href="`/admin/${role}s`" variant="secondary">Cancel</UiButton>
                <UiButton type="submit" :loading="form.processing">
                    <template #leading><Send class="h-4 w-4" /></template>
                    Create and send credentials
                </UiButton>
            </div>
        </form>
    </AppLayout>
</template>
