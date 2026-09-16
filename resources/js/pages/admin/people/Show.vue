<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, KeyRound, ShieldCheck, ShieldOff, MailCheck, MailWarning,
    PhoneCall, Send, CheckCircle2, XCircle, Clock,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';

const props = defineProps({
    role: { type: String, required: true },
    roleLabel: { type: String, required: true },
    person: { type: Object, required: true },
    colleges: { type: Array, default: () => [] },
    deliveries: { type: Array, default: () => [] },
});

const isStudent = props.role === 'student';
const resending = ref(false);

const statusTones = { active: 'success', suspended: 'danger', pending: 'warning' };
const deliveryIcons = { sent: CheckCircle2, failed: XCircle, queued: Clock };
const deliveryTones = { sent: 'text-signal-500', failed: 'text-danger-500', queued: 'text-warn-500' };

const form = useForm({
    name: props.person.name,
    email: props.person.email,
    mobile: props.person.mobile ?? '',
    company: props.person.profile.company ?? '',
    designation: props.person.profile.designation ?? '',
    gstin: props.person.profile.gstin ?? '',
    city: props.person.profile.city ?? '',
    state: props.person.profile.state ?? '',
    college_id: props.person.profile.collegeId ?? null,
    enrollment_number: props.person.profile.enrollmentNumber ?? '',
    course_of_study: props.person.profile.courseOfStudy ?? '',
    current_semester: props.person.profile.currentSemester ?? '',
    graduation_year: props.person.profile.graduationYear ?? '',
});

function save() {
    form.put(`/admin/${props.role}s/${props.person.id}`, { preserveScroll: true });
}

function setStatus(status) {
    router.put(`/admin/${props.role}s/${props.person.id}/status`, { status }, { preserveScroll: true });
}

function resend() {
    router.post(`/admin/${props.role}s/${props.person.id}/resend-credentials`, {}, {
        preserveScroll: true,
        onFinish: () => (resending.value = false),
    });
}
</script>

<template>
    <Head :title="person.name" />

    <AppLayout
        :title="person.name"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: `${roleLabel}s`, href: `/admin/${role}s` },
            { label: person.name },
        ]"
    >
        <div class="mx-auto max-w-5xl">
            <PageHeader :title="person.name" :description="person.email">
                <template #actions>
                    <UiButton :href="`/admin/${role}s`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>

                    <UiButton
                        v-if="person.status === 'active'"
                        variant="secondary"
                        size="sm"
                        @click="setStatus('suspended')"
                    >
                        <template #leading><ShieldOff class="h-3.5 w-3.5" /></template>
                        Suspend
                    </UiButton>
                    <UiButton v-else variant="secondary" size="sm" @click="setStatus('active')">
                        <template #leading><ShieldCheck class="h-3.5 w-3.5" /></template>
                        Reactivate
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="space-y-5 lg:col-span-2">
                    <form class="space-y-5" @submit.prevent="save">
                        <FormSection title="Account">
                            <UiFormField label="Full name" required :error="form.errors.name">
                                <template #default="field">
                                    <UiInput :id="field.id" v-model="form.name" :invalid="field.invalid" required />
                                </template>
                            </UiFormField>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <UiFormField
                                    label="Email"
                                    required
                                    hint="Changing this marks it unconfirmed again."
                                    :error="form.errors.email"
                                >
                                    <template #default="field">
                                        <UiInput :id="field.id" v-model="form.email" type="email" :invalid="field.invalid" required />
                                    </template>
                                </UiFormField>

                                <UiFormField label="Mobile" :error="form.errors.mobile">
                                    <template #default="field">
                                        <UiInput :id="field.id" v-model="form.mobile" type="tel" :invalid="field.invalid" />
                                    </template>
                                </UiFormField>
                            </div>
                        </FormSection>

                        <FormSection v-if="!isStudent" title="Business details">
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

                        <FormSection v-else title="College details">
                            <UiFormField label="College" :error="form.errors.college_id">
                                <template #default="field">
                                    <UiCombobox
                                        :id="field.id"
                                        v-model="form.college_id"
                                        :options="colleges"
                                        placeholder="Not linked to a college"
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
                                        <UiInput :id="field.id" v-model="form.course_of_study" :invalid="field.invalid" />
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

                        <div class="flex justify-end">
                            <UiButton type="submit" :loading="form.processing" :disabled="!form.isDirty">
                                Save changes
                            </UiButton>
                        </div>
                    </form>
                </div>

                <div class="space-y-5">
                    <UiCard>
                        <div class="flex items-center gap-3">
                            <UiAvatar :name="person.name" size="lg" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ person.name }}</p>
                                <UiBadge :tone="statusTones[person.status] ?? 'neutral'" size="sm" dot>
                                    {{ person.status }}
                                </UiBadge>
                            </div>
                        </div>

                        <dl class="mt-5 space-y-2.5 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <dt class="flex items-center gap-2" style="color: var(--text-muted)">
                                    <component :is="person.emailVerified ? MailCheck : MailWarning" class="h-3.5 w-3.5" />
                                    Email
                                </dt>
                                <dd>
                                    <UiBadge :tone="person.emailVerified ? 'success' : 'warning'" size="sm">
                                        {{ person.emailVerified ? 'Confirmed' : 'Unconfirmed' }}
                                    </UiBadge>
                                </dd>
                            </div>

                            <div v-if="person.mobile" class="flex items-center justify-between gap-2">
                                <dt class="flex items-center gap-2" style="color: var(--text-muted)">
                                    <PhoneCall class="h-3.5 w-3.5" /> Mobile
                                </dt>
                                <dd>
                                    <UiBadge :tone="person.mobileVerified ? 'success' : 'warning'" size="sm">
                                        {{ person.mobileVerified ? 'Confirmed' : 'Unconfirmed' }}
                                    </UiBadge>
                                </dd>
                            </div>

                            <div class="flex items-center justify-between gap-2">
                                <dt class="flex items-center gap-2" style="color: var(--text-muted)">
                                    <ShieldCheck class="h-3.5 w-3.5" /> Two factor
                                </dt>
                                <dd>
                                    <UiBadge :tone="person.twoFactorEnabled ? 'success' : 'neutral'" size="sm">
                                        {{ person.twoFactorEnabled ? 'On' : 'Off' }}
                                    </UiBadge>
                                </dd>
                            </div>

                            <div class="flex items-center justify-between gap-2">
                                <dt style="color: var(--text-muted)">Last sign in</dt>
                                <dd style="color: var(--text-base)">{{ person.lastLoginAgo ?? 'Never' }}</dd>
                            </div>

                            <div class="flex items-center justify-between gap-2">
                                <dt style="color: var(--text-muted)">Added</dt>
                                <dd style="color: var(--text-base)">{{ person.createdAt }}</dd>
                            </div>
                        </dl>
                    </UiCard>

                    <UiCard>
                        <template #header>
                            <h2 class="flex items-center gap-2 text-base font-semibold">
                                <KeyRound class="h-4 w-4" style="color: var(--text-muted)" aria-hidden="true" />
                                Credentials
                            </h2>
                        </template>

                        <p
                            v-if="person.mustChangePassword"
                            class="rounded-xl px-3.5 py-2.5 text-sm"
                            style="background: color-mix(in oklab, var(--color-warn-500) 10%, transparent); color: var(--text-base)"
                        >
                            Still on the temporary password we issued. Until they replace it, that
                            password also exists in their inbox.
                        </p>
                        <p v-else class="text-sm" style="color: var(--text-muted)">
                            They have set their own password.
                        </p>

                        <UiButton class="mt-4" variant="secondary" size="sm" block @click="resending = true">
                            <template #leading><Send class="h-3.5 w-3.5" /></template>
                            Send new credentials
                        </UiButton>

                        <ul v-if="deliveries.length" class="mt-4 space-y-2 border-t pt-4" style="border-color: var(--border-subtle)">
                            <li v-for="delivery in deliveries" :key="delivery.id" class="flex items-start gap-2 text-xs">
                                <component
                                    :is="deliveryIcons[delivery.status] ?? Clock"
                                    :class="['mt-0.5 h-3.5 w-3.5 shrink-0', deliveryTones[delivery.status]]"
                                />
                                <span class="min-w-0">
                                    <span class="block" style="color: var(--text-base)">
                                        {{ delivery.channel === 'mail' ? 'Email' : 'SMS' }} to {{ delivery.destination }}
                                    </span>
                                    <span class="block" style="color: var(--text-muted)">
                                        {{ delivery.status }} · {{ delivery.at }}
                                        <template v-if="delivery.by"> · by {{ delivery.by }}</template>
                                    </span>
                                    <span v-if="delivery.reason" class="block text-danger-500">{{ delivery.reason }}</span>
                                </span>
                            </li>
                        </ul>
                    </UiCard>
                </div>
            </div>
        </div>

        <UiModal
            :open="resending"
            title="Send new credentials?"
            description="A fresh temporary password is generated and sent. The current password stops working immediately, and any signed in device is cut off."
            size="sm"
            @close="resending = false"
        >
            <template #footer>
                <UiButton variant="secondary" @click="resending = false">Cancel</UiButton>
                <UiButton @click="resend">Generate and send</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
