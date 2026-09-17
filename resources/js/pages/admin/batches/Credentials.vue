<script setup>
/**
 * Issuing certificates and internship paperwork for one batch.
 *
 * Issuing is a decision somebody takes, not a nightly job: a college relies on
 * these, and an automatic certificate for a student who scraped through on a
 * technicality is a document we would have to withdraw.
 */
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Award, Download, Ban, FileBadge, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    students: { type: Array, default: () => [] },
});

/* ------------------------------------------------------- certificates */

const forcing = ref(null);

function issue(student, force = false) {
    router.post(`/admin/enrolments/${student.enrolmentId}/certificate`, { force }, {
        preserveScroll: true,
        onSuccess: () => { forcing.value = null; },
    });
}

function considerIssuing(student) {
    // The server refuses on its own, but asking here means the person issuing
    // sees why before they override it rather than after.
    if (student.passing) {
        issue(student);
        return;
    }

    forcing.value = student;
}

/* ---------------------------------------------------------- revoking */

const revoking = ref(null);
const revokeForm = useForm({ reason: '' });

function revoke() {
    revokeForm.post(`/admin/certificates/${revoking.value.certificate.id}/revoke`, {
        preserveScroll: true,
        onSuccess: () => {
            revoking.value = null;
            revokeForm.reset();
        },
    });
}

/* --------------------------------------------------------- documents */

const documenting = ref(null);
const documentForm = useForm({ kind: 'completion_certificate' });

function openDocument(student) {
    documentForm.clearErrors();
    documentForm.kind = props.kinds[0]?.value ?? 'completion_certificate';
    documenting.value = student;
}

function issueDocument() {
    documentForm.post(`/admin/enrolments/${documenting.value.enrolmentId}/documents`, {
        preserveScroll: true,
        onSuccess: () => { documenting.value = null; },
    });
}
</script>

<template>
    <Head :title="`Credentials — ${batch.name}`" />

    <AppLayout
        title="Credentials"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: batch.name, href: `/admin/batches/${batch.id}/run` },
            { label: 'Credentials' },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-4">
            <PageHeader
                title="Certificates and documents"
                :description="`${batch.course} · pass mark ${batch.passPercent}%${batch.minimumAttendance ? `, ${batch.minimumAttendance}% attendance` : ''}.`"
            >
                <template #actions>
                    <UiButton :href="`/admin/batches/${batch.id}/run`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Batch
                    </UiButton>
                </template>
            </PageHeader>

            <p
                v-if="!batch.issuesCertificate"
                class="flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-xs"
                style="background: var(--surface-sunken); color: var(--text-muted)"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                This course is not set to issue certificates. Turn that on in the course details first.
            </p>

            <UiEmptyState
                v-if="!students.length"
                :icon="Award"
                title="Nobody enrolled"
                description="Add students to the batch first."
            />

            <UiCard v-else padding="p-0">
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="student in students"
                        :key="student.enrolmentId"
                        class="flex flex-wrap items-center gap-3 px-5 py-4 sm:px-6"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{ student.name }}</span>
                                <UiBadge v-if="student.overall !== null" :tone="student.passing ? 'success' : 'warning'" size="sm">
                                    {{ student.overall }}%<span v-if="student.grade"> · {{ student.grade }}</span>
                                </UiBadge>
                                <UiBadge v-else size="sm">no marks yet</UiBadge>
                                <UiBadge v-if="student.shortOnAttendance" tone="danger" size="sm">
                                    {{ student.attendance }}% attendance
                                </UiBadge>
                            </span>

                            <span class="mt-1 block text-xs" style="color: var(--text-muted)">
                                {{ student.progress }}% of the course finished
                            </span>

                            <ul v-if="student.documents.length" class="mt-2 flex flex-wrap gap-1.5">
                                <li v-for="document in student.documents" :key="document.id">
                                    <a
                                        :href="`/admin/internship-documents/${document.id}/download`"
                                        class="inline-flex items-center gap-1 rounded-full bg-[var(--surface-sunken)] px-2 py-0.5 text-[0.68rem] hover:underline"
                                    >
                                        <FileBadge class="h-3 w-3" />
                                        {{ document.kindLabel }}
                                    </a>
                                </li>
                            </ul>
                        </span>

                        <span class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <template v-if="student.certificate">
                                <UiBadge :tone="student.certificate.revoked ? 'danger' : 'success'" size="sm">
                                    {{ student.certificate.revoked ? 'withdrawn' : student.certificate.number }}
                                </UiBadge>
                                <UiButton
                                    :href="`/admin/certificates/${student.certificate.id}/download`"
                                    :inertia="false"
                                    variant="ghost"
                                    size="xs"
                                >
                                    <Download class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton
                                    v-if="!student.certificate.revoked"
                                    variant="ghost"
                                    size="xs"
                                    @click="revoking = student"
                                >
                                    <Ban class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                                </UiButton>
                            </template>

                            <UiButton
                                v-else-if="batch.issuesCertificate"
                                size="xs"
                                :variant="student.passing ? 'primary' : 'secondary'"
                                @click="considerIssuing(student)"
                            >
                                <template #leading><Award class="h-3 w-3" /></template>
                                Certificate
                            </UiButton>

                            <UiButton v-if="batch.isInternship" variant="secondary" size="xs" @click="openDocument(student)">
                                <template #leading><FileBadge class="h-3 w-3" /></template>
                                Document
                            </UiButton>
                        </span>
                    </li>
                </ul>
            </UiCard>
        </div>

        <!-- ------------------------------------------ issuing anyway -->
        <UiModal
            :open="forcing !== null"
            title="This student is not passing"
            @close="forcing = null"
        >
            <p class="text-sm" style="color: var(--text-muted)">
                {{ forcing?.name }} is on
                {{ forcing?.overall === null ? 'no marks yet' : `${forcing?.overall}%` }}<span
                    v-if="forcing?.shortOnAttendance"
                >, with {{ forcing?.attendance }}% attendance</span>.
                Issuing anyway is recorded against your name in the audit log, with the override noted.
            </p>

            <template #footer>
                <UiButton variant="ghost" @click="forcing = null">Not now</UiButton>
                <UiButton @click="issue(forcing, true)">Issue anyway</UiButton>
            </template>
        </UiModal>

        <!-- ---------------------------------------------- withdrawing -->
        <UiModal
            :open="revoking !== null"
            title="Withdraw this certificate"
            description="The code keeps working and tells whoever checks it that the certificate was withdrawn. It is never deleted."
            @close="revoking = null"
        >
            <form @submit.prevent="revoke">
                <UiFormField label="Reason" required :error="revokeForm.errors.reason">
                    <UiTextarea v-model="revokeForm.reason" :rows="3" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="revoking = null">Cancel</UiButton>
                <UiButton :loading="revokeForm.processing" @click="revoke">Withdraw</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------- documents -->
        <UiModal
            :open="documenting !== null"
            :title="`Issue a document for ${documenting?.name ?? ''}`"
            @close="documenting = null"
        >
            <form @submit.prevent="issueDocument">
                <UiFormField label="Document" required :error="documentForm.errors.kind">
                    <UiSelect v-model="documentForm.kind">
                        <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </UiSelect>
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="documenting = null">Cancel</UiButton>
                <UiButton :loading="documentForm.processing" @click="issueDocument">Issue</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
