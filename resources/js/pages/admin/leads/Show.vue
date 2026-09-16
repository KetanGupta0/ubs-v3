<script setup>
/**
 * One enquiry, with everything needed to answer it and turn it into an account.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, Mail, Phone, Building2, Globe, Clock, IndianRupee,
    UserPlus, Trash2, MessageSquarePlus, ExternalLink, GraduationCap,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';

const props = defineProps({
    lead: { type: Object, required: true },
    notes: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
});

const converting = ref(false);
const confirmingDelete = ref(false);

const statusTones = {
    new: 'brand', contacted: 'accent', qualified: 'warning',
    converted: 'success', lost: 'neutral',
};

const noteForm = useForm({ body: '' });

const convertForm = useForm({
    name: props.lead.name,
    email: props.lead.email,
    mobile: props.lead.mobile ?? '',
    company: props.lead.company ?? props.lead.collegeName ?? '',
    // A training or internship enquiry becomes a student; everything else a client.
    role: ['training', 'internship'].includes(props.lead.interest) ? 'student' : 'client',
    note: '',
});

function setStatus(status) {
    router.put(`/admin/leads/${props.lead.id}`, { status }, { preserveScroll: true });
}

function setAssignee(assigned_to) {
    router.put(`/admin/leads/${props.lead.id}`, { assigned_to: assigned_to || null }, { preserveScroll: true });
}

function addNote() {
    noteForm.post(`/admin/leads/${props.lead.id}/notes`, {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

function convert() {
    convertForm.post(`/admin/leads/${props.lead.id}/convert`);
}

const facts = [
    { icon: Mail, label: 'Email', value: props.lead.email, href: `mailto:${props.lead.email}` },
    { icon: Phone, label: 'Mobile', value: props.lead.mobile, href: `tel:${props.lead.mobile}` },
    { icon: Building2, label: 'Company', value: props.lead.company },
    { icon: GraduationCap, label: 'College', value: props.lead.collegeName },
    { icon: UserPlus, label: 'Students', value: props.lead.studentCount },
    { icon: IndianRupee, label: 'Budget', value: props.lead.budgetBand },
    { icon: Clock, label: 'Timeline', value: props.lead.timeline },
    { icon: Globe, label: 'Came from', value: props.lead.sourcePage },
].filter((fact) => fact.value);
</script>

<template>
    <Head :title="`Enquiry ${lead.reference}`" />

    <AppLayout
        :title="lead.name"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Enquiries', href: '/admin/leads' },
            { label: lead.reference },
        ]"
    >
        <div class="mx-auto max-w-5xl">
            <PageHeader :title="lead.name" :description="lead.subject">
                <template #actions>
                    <UiButton href="/admin/leads" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>

                    <UiButton
                        v-if="!lead.convertedUser"
                        size="sm"
                        @click="converting = true"
                    >
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Create an account
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="space-y-5 lg:col-span-2">
                    <UiCard>
                        <template #header>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="text-base font-semibold">What they wrote</h2>
                                <span class="font-mono text-xs" style="color: var(--text-muted)">{{ lead.reference }}</span>
                            </div>
                        </template>

                        <p class="whitespace-pre-line text-sm leading-relaxed" style="color: var(--text-base)">
                            {{ lead.message }}
                        </p>

                        <p class="mt-4 border-t pt-3 text-xs" style="border-color: var(--border-subtle); color: var(--text-muted)">
                            Received {{ lead.receivedAt }} · {{ lead.receivedAgo }}
                            <span v-if="lead.ipAddress"> · from {{ lead.ipAddress }}</span>
                        </p>
                    </UiCard>

                    <UiCard>
                        <template #header>
                            <h2 class="text-base font-semibold">Internal notes</h2>
                        </template>

                        <form class="space-y-3" @submit.prevent="addNote">
                            <UiTextarea
                                v-model="noteForm.body"
                                :rows="3"
                                placeholder="What happened when you contacted them?"
                                :invalid="Boolean(noteForm.errors.body)"
                            />
                            <p v-if="noteForm.errors.body" class="text-xs font-medium text-danger-500">
                                {{ noteForm.errors.body }}
                            </p>

                            <UiButton type="submit" size="sm" :loading="noteForm.processing" :disabled="!noteForm.body.trim()">
                                <template #leading><MessageSquarePlus class="h-3.5 w-3.5" /></template>
                                Add note
                            </UiButton>
                        </form>

                        <ul v-if="notes.length" class="mt-5 space-y-3 border-t pt-4" style="border-color: var(--border-subtle)">
                            <li v-for="note in notes" :key="note.id">
                                <p class="text-sm" style="color: var(--text-base)">{{ note.body }}</p>
                                <p class="mt-1 text-xs" style="color: var(--text-muted)">
                                    {{ note.author }} · {{ note.at }}
                                </p>
                            </li>
                        </ul>
                    </UiCard>
                </div>

                <div class="space-y-5">
                    <UiCard>
                        <template #header>
                            <h2 class="text-base font-semibold">Handling</h2>
                        </template>

                        <div class="space-y-4">
                            <UiFormField label="Status">
                                <template #default="field">
                                    <UiSelect
                                        :id="field.id"
                                        :model-value="lead.status"
                                        :options="statuses.map((s) => ({ value: s, label: s }))"
                                        @update:model-value="setStatus"
                                    />
                                </template>
                            </UiFormField>

                            <UiFormField label="Assigned to">
                                <template #default="field">
                                    <UiSelect
                                        :id="field.id"
                                        :model-value="lead.assignedTo ?? ''"
                                        placeholder="Nobody yet"
                                        :options="assignees.map((a) => ({ value: a.id, label: a.name }))"
                                        @update:model-value="setAssignee"
                                    />
                                </template>
                            </UiFormField>

                            <div v-if="lead.convertedUser" class="rounded-xl border p-3.5" style="border-color: var(--border-subtle); background: var(--surface-sunken)">
                                <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                    Became an account
                                </p>
                                <Link
                                    :href="`/admin/clients/${lead.convertedUser.id}`"
                                    class="mt-1.5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400"
                                >
                                    {{ lead.convertedUser.name }}
                                    <ExternalLink class="h-3 w-3" />
                                </Link>
                            </div>
                        </div>
                    </UiCard>

                    <UiCard>
                        <template #header>
                            <h2 class="text-base font-semibold">Details</h2>
                        </template>

                        <dl class="space-y-3">
                            <div v-for="fact in facts" :key="fact.label" class="flex items-start gap-2.5">
                                <component :is="fact.icon" class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                                <div class="min-w-0">
                                    <dt class="text-xs" style="color: var(--text-muted)">{{ fact.label }}</dt>
                                    <dd class="text-sm break-words" style="color: var(--text-strong)">
                                        <a v-if="fact.href" :href="fact.href" class="hover:underline">{{ fact.value }}</a>
                                        <template v-else>{{ fact.value }}</template>
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <UiBadge class="mt-4" :tone="statusTones[lead.status] ?? 'neutral'" dot>
                            {{ lead.interest }} enquiry
                        </UiBadge>
                    </UiCard>

                    <UiButton variant="ghost" size="sm" block @click="confirmingDelete = true">
                        <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                        Remove this enquiry
                    </UiButton>
                </div>
            </div>
        </div>

        <!-- ------------------------------------------------------- conversion -->
        <UiModal
            :open="converting"
            title="Create an account from this enquiry"
            description="A temporary password is generated and sent to the email and mobile below. They will have to replace it before they can use the account."
            size="lg"
            @close="converting = false"
        >
            <form class="space-y-4" @submit.prevent="convert">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="convertForm.errors.name">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="convertForm.name" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField label="Account type" required :error="convertForm.errors.role">
                        <template #default="field">
                            <UiSelect
                                :id="field.id"
                                v-model="convertForm.role"
                                :options="[
                                    { value: 'client', label: 'Client' },
                                    { value: 'student', label: 'Student' },
                                ]"
                            />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Email" required :error="convertForm.errors.email">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="convertForm.email" type="email" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField
                        label="Mobile"
                        hint="The credentials go here as well as by email."
                        :error="convertForm.errors.mobile"
                    >
                        <template #default="field">
                            <UiInput :id="field.id" v-model="convertForm.mobile" type="tel" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Company or college" :error="convertForm.errors.company">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="convertForm.company" :invalid="field.invalid" />
                    </template>
                </UiFormField>

                <UiFormField
                    label="A line to include in the welcome message"
                    hint="Optional. Something that shows a person read their enquiry."
                    :error="convertForm.errors.note"
                >
                    <template #default="field">
                        <UiTextarea
                            :id="field.id"
                            v-model="convertForm.note"
                            :rows="2"
                            placeholder="Good speaking with you this morning about the warehouse system."
                        />
                    </template>
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="secondary" @click="converting = false">Cancel</UiButton>
                <UiButton :loading="convertForm.processing" @click="convert">
                    Create and send credentials
                </UiButton>
            </template>
        </UiModal>

        <UiModal
            :open="confirmingDelete"
            title="Remove this enquiry?"
            description="It is soft deleted, so it can be recovered from the database if needed."
            size="sm"
            @close="confirmingDelete = false"
        >
            <template #footer>
                <UiButton variant="secondary" @click="confirmingDelete = false">Cancel</UiButton>
                <UiButton
                    variant="danger"
                    @click="router.delete(`/admin/leads/${lead.id}`)"
                >
                    Remove
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
