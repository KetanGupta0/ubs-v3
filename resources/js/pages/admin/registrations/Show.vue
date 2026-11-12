<script setup>
/**
 * One registration form: its address and QR code, and everybody who came in
 * through it, with approve, reject and move to another batch.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, Copy, Download, ExternalLink, Pencil, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { toast } from '@/support/toast';

const props = defineProps({
    form: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    table: { type: Object, required: true },
    batches: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const statusTones = {
    received: 'neutral',
    awaiting_confirmation: 'brand',
    awaiting_review: 'warning',
    approved: 'success',
    rejected: 'danger',
    declined: 'neutral',
};

const states = {
    open: 'Open',
    not_yet: 'Not open yet',
    full: 'Full',
    closed: 'Closed',
};

const current = ref(null);
const rejectForm = useForm({ reason: '' });
const moveForm = useForm({ batch_id: '' });

function open(row) {
    current.value = row;
    rejectForm.reset();
    rejectForm.clearErrors();
    moveForm.batch_id = row.batchId ?? '';
    moveForm.clearErrors();
}

const close = () => { current.value = null; };
const keep = { preserveScroll: true, onSuccess: close };
const base = computed(() => `/admin/registration-forms/${props.form.id}/registrations/${current.value?.id}`);

const approve = () => router.post(`${base.value}/approve`, {}, keep);
const reject = () => rejectForm.post(`${base.value}/reject`, keep);
const move = () => moveForm.put(`${base.value}/batch`, keep);

const canApprove = computed(() => current.value
    && ['awaiting_review', 'rejected'].includes(current.value.status)
    && current.value.confirmed
    && current.value.studentId);
const canReject = computed(() => current.value
    && ['awaiting_review', 'awaiting_confirmation', 'received'].includes(current.value.status));

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.form.url);
        toast.success('Link copied.');
    } catch {
        toast.error('Could not copy. Select the address and copy it by hand.');
    }
}
</script>

<template>
    <Head :title="form.title" />

    <AppLayout
        :title="form.title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Registration forms', href: '/admin/registration-forms' },
            { label: form.title },
        ]"
    >
        <div class="mx-auto max-w-7xl">
            <PageHeader :title="form.title" :description="`${form.course}${form.batch ? ` · ${form.batch}` : ''} · ${form.fee} · ${form.approval === 'review' ? 'each one approved by the team' : 'on the course once the address is confirmed'}`">
                <template #actions>
                    <UiButton href="/admin/registration-forms" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All forms
                    </UiButton>
                    <UiButton v-if="canManage" :href="`/admin/registration-forms/${form.id}/edit`" variant="secondary" size="sm">
                        <template #leading><Pencil class="h-3.5 w-3.5" /></template>
                        Change
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-4 lg:grid-cols-[1fr_auto]">
                <div class="grid content-start gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile label="Now" :value="states[form.state] ?? form.state" :tone="form.state === 'open' ? 'success' : 'neutral'" />
                    <StatTile label="Waiting for review" :value="counts.awaiting_review ?? 0" :tone="counts.awaiting_review ? 'warning' : 'neutral'" />
                    <StatTile label="Waiting for the student" :value="counts.awaiting_confirmation ?? 0" hint="Existing students confirm from their account" />
                    <StatTile label="On the course" :value="counts.approved ?? 0" tone="success" />
                    <StatTile label="Places" :value="form.capacity ? `${form.placesTaken} of ${form.capacity}` : `${form.placesTaken}, no limit`" />
                    <StatTile label="Opens" :value="form.opensAt ?? 'Now'" />
                    <StatTile label="Closes" :value="form.closesAt ?? 'Not set'" :hint="form.closesAt ? null : 'Open until switched off'" />
                </div>

                <UiCard class="flex flex-col items-center gap-3 p-4 lg:w-64">
                    <img :src="form.qr" alt="QR code that opens the form" class="h-40 w-40 rounded-lg bg-white p-1" />
                    <p class="w-full break-all text-center text-xs" style="color: var(--text-muted)">{{ form.url }}</p>
                    <div class="flex flex-wrap justify-center gap-1.5">
                        <UiButton variant="secondary" size="xs" @click="copyLink">
                            <template #leading><Copy class="h-3 w-3" /></template>
                            Copy link
                        </UiButton>
                        <UiButton :href="`/admin/registration-forms/${form.id}/qr`" variant="secondary" size="xs" :inertia="false">
                            <template #leading><Download class="h-3 w-3" /></template>
                            QR for print
                        </UiButton>
                        <UiButton :href="form.url" variant="ghost" size="xs" :inertia="false" target="_blank">
                            <template #leading><ExternalLink class="h-3 w-3" /></template>
                            Open
                        </UiButton>
                    </div>
                </UiCard>
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by name, email or mobile…"
                empty-title="Nobody has registered yet"
                empty-description="Share the link or print the QR code."
                :only="['table', 'counts']"
            >
                <template #cell:name="{ row }">
                    <span class="block font-medium" style="color: var(--text-strong)">{{ row.name }}</span>
                    <span v-if="row.newAccount" class="block text-xs" style="color: var(--text-muted)">New account</span>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton variant="ghost" size="xs" @click="open(row)">{{ canManage ? 'Read and decide' : 'Read' }}</UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <button type="button" class="block w-full text-left" @click="open(row)">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.name }}</span>
                            <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 truncate text-xs" style="color: var(--text-muted)">{{ row.email }} · {{ row.created_at }}</p>
                    </button>
                </template>
            </DataTable>
        </div>

        <UiModal
            :open="current !== null"
            :title="current?.name ?? ''"
            :description="current ? `${current.email}${current.mobile ? ` · ${current.mobile}` : ''} · registered ${current.created_at}` : ''"
            size="lg"
            @close="close"
        >
            <div v-if="current" class="space-y-5">
                <div class="flex flex-wrap items-center gap-2">
                    <UiBadge :tone="statusTones[current.status] ?? 'neutral'" dot>{{ current.statusLabel }}</UiBadge>
                    <UiBadge v-if="current.newAccount" tone="brand">Account made by this form</UiBadge>
                    <a
                        v-if="current.studentId"
                        :href="`/admin/students/${current.studentId}`"
                        class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                    >Student record</a>
                </div>

                <p v-if="current.note" class="rounded-lg px-3 py-2 text-sm" style="background: var(--surface-sunken)">{{ current.note }}</p>

                <dl v-if="current.details.length || current.answers.length" class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <div v-for="item in [...current.details, ...current.answers]" :key="item.label" class="min-w-0">
                        <dt class="text-xs" style="color: var(--text-muted)">{{ item.label }}</dt>
                        <dd class="mt-0.5 whitespace-pre-line break-words text-sm">{{ item.value || '—' }}</dd>
                    </div>
                </dl>

                <template v-if="canManage">
                    <p v-if="current.status === 'awaiting_confirmation'" class="text-sm" style="color: var(--text-muted)">
                        This address belongs to an existing student, who has been asked to confirm it from their account.
                        Until they do, it could be somebody else typing their address, so it cannot be approved.
                    </p>

                    <div v-if="canApprove" class="flex flex-wrap items-center gap-2">
                        <UiButton size="sm" @click="approve">
                            <template #leading><Check class="h-3.5 w-3.5" /></template>
                            Approve and put on the course
                        </UiButton>
                        <span class="text-xs" style="color: var(--text-muted)">{{ current.batch ? `Into ${current.batch}.` : 'Without a batch; move them to one first if the course runs in batches.' }}</span>
                    </div>

                    <form v-if="batches.length && !['rejected', 'declined'].includes(current.status)" class="flex flex-wrap items-end gap-2" @submit.prevent="move">
                        <UiFormField label="Batch" class="min-w-56 flex-1" :error="moveForm.errors.batch_id">
                            <UiSelect v-model="moveForm.batch_id" :options="batches" placeholder="Choose a batch" />
                        </UiFormField>
                        <UiButton type="submit" variant="secondary" size="sm" :loading="moveForm.processing" :disabled="!moveForm.batch_id || String(moveForm.batch_id) === String(current.batchId)">
                            {{ current.status === 'approved' ? 'Move them' : 'Set the batch' }}
                        </UiButton>
                    </form>

                    <form v-if="canReject" class="space-y-2" @submit.prevent="reject">
                        <UiFormField
                            label="Reason"
                            :hint="current.confirmed ? 'Optional. Sent to them with the decision.' : 'Optional. Kept here; they are not told, because they never confirmed it.'"
                            :error="rejectForm.errors.reason"
                        >
                            <UiTextarea v-model="rejectForm.reason" :rows="2" />
                        </UiFormField>
                        <UiButton type="submit" variant="ghost" size="sm" :loading="rejectForm.processing">
                            <template #leading><X class="h-3.5 w-3.5" /></template>
                            Reject
                        </UiButton>
                    </form>
                </template>
            </div>

            <template #footer>
                <UiButton variant="ghost" @click="close">Close</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
