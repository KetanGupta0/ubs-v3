<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { UserPlus, Settings2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiProgress from '@/components/UI/UiProgress.vue';

const props = defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    courses: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    canTakePayments: { type: Boolean, default: false },
    studentOptions: { type: Array, default: null },
});

const statusTones = { active: 'success', on_hold: 'warning', completed: 'brand', dropped: 'neutral' };

const done = (close) => ({ preserveScroll: true, onSuccess: () => close() });

/* ------------------------------------------------------------ enrol someone */

const enrolOpen = ref(false);
const enrolForm = useForm({
    student_id: null,
    course_id: null,
    batch_id: null,
    waive_fee: false,
    notify: true,
    over_capacity: false,
});

// Options arrive as { value, label, courseId, full }: the id is `value`.
const batchesForCourse = (courseId) => props.batches
    .filter((batch) => batch.courseId === Number(courseId))
    .map((batch) => ({ value: batch.value, label: batch.full ? `${batch.label} (full)` : batch.label }));

const isFullBatch = (id) => props.batches.find((batch) => batch.value === Number(id))?.full ?? false;

const enrolBatches = computed(() => [{ value: '', label: 'No batch yet' }, ...batchesForCourse(enrolForm.course_id)]);
const enrolBatchFull = computed(() => isFullBatch(enrolForm.batch_id));

/*
 * The student list is fetched only when somebody opens the form, and kept
 * here once it has arrived: any later response (a validation error, say)
 * leaves the optional prop out, and the form should not empty itself then.
 */
const students = ref(props.studentOptions);
watch(() => props.studentOptions, (list) => { if (list !== null) students.value = list; });

function openEnrol() {
    enrolForm.reset();
    enrolForm.clearErrors();
    enrolOpen.value = true;

    if (students.value === null) {
        router.reload({ only: ['studentOptions'] });
    }
}

function submitEnrol() {
    enrolForm
        .transform((data) => ({ ...data, batch_id: data.batch_id || null }))
        .post('/admin/enrolments', done(() => { enrolOpen.value = false; }));
}

/* ----------------------------------------------------------- manage one row */

const current = ref(null);
const statusForm = useForm({ status: '' });
const moveForm = useForm({ batch_id: '', over_capacity: false });
const payForm = useForm({ amount: null, method: 'bank transfer', note: '' });
const waiveForm = useForm({ reason: '' });

const moveOptions = computed(() => (current.value
    ? batchesForCourse(current.value.courseId).filter((batch) => batch.value !== current.value.batchId)
    : []));
const moveBatchFull = computed(() => isFullBatch(moveForm.batch_id));

function openManage(row) {
    current.value = row;
    statusForm.status = row.status;
    moveForm.reset();
    payForm.reset();
    payForm.amount = row.balance ? (row.balance / 100).toFixed(2) : null;
    waiveForm.reset();
    [statusForm, moveForm, payForm, waiveForm].forEach((form) => form.clearErrors());
}

const closeManage = () => { current.value = null; };

const submitStatus = () => statusForm.put(`/admin/enrolments/${current.value.id}/status`, done(closeManage));
const submitMove = () => moveForm.put(`/admin/enrolments/${current.value.id}/batch`, done(closeManage));
const submitPayment = () => payForm.post(`/admin/billing/${current.value.paymentRequestId}/offline`, done(closeManage));
const submitWaive = () => waiveForm.post(`/admin/enrolments/${current.value.id}/waive`, done(closeManage));
</script>

<template>
    <Head title="Enrolments" />

    <AppLayout title="Enrolments" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Enrolments' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Enrolments"
                description="Everyone on every course. Put somebody on a course, move them between batches, take a payment made by bank transfer, or let them off a fee."
            >
                <template v-if="canManage" #actions>
                    <UiButton size="sm" @click="openEnrol">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Enrol a student
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Active" :value="counts.active ?? 0" tone="success" />
                <StatTile
                    label="Fee not paid"
                    :value="counts.unpaid ?? 0"
                    :tone="counts.unpaid ? 'warning' : 'neutral'"
                    hint="Active or on hold, with lessons waiting on payment"
                />
                <StatTile label="On hold" :value="counts.onHold ?? 0" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by student, email, course or batch…"
                empty-title="No enrolments match"
                empty-description="Try clearing the filters."
                :only="['table', 'counts']"
            >
                <template #cell:student="{ row }">
                    <Link :href="`/admin/students/${row.studentId}`" class="block hover:underline">
                        <span class="block font-medium" style="color: var(--text-strong)">{{ row.student }}</span>
                        <span class="block text-xs" style="color: var(--text-muted)">{{ row.email }}</span>
                    </Link>
                </template>

                <template #cell:batch="{ row }">
                    <span :style="row.batch ? {} : { color: 'var(--text-muted)' }">{{ row.batch ?? 'No batch' }}</span>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>

                <template #cell:fee="{ row }">
                    <span :style="row.hasPaid ? {} : { color: 'var(--color-warn-600)' }">{{ row.fee }}</span>
                </template>

                <template #cell:progress_percent="{ row }">
                    <div class="flex min-w-24 items-center gap-2">
                        <UiProgress :value="row.progress_percent" size="sm" :show-value="false" class="flex-1" />
                        <span class="text-xs tabular-nums" style="color: var(--text-muted)">{{ row.progress_percent }}%</span>
                    </div>
                </template>

                <template v-if="canManage" #rowActions="{ row }">
                    <UiButton variant="ghost" size="xs" @click="openManage(row)">
                        <template #leading><Settings2 class="h-3 w-3" /></template>
                        Manage
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <button type="button" class="block w-full text-left" :disabled="!canManage" @click="canManage && openManage(row)">
                        <div class="flex items-center justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold">{{ row.student }}</span>
                                <span class="block truncate text-xs" style="color: var(--text-muted)">{{ row.course }} · {{ row.batch ?? 'No batch' }}</span>
                            </span>
                            <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-2 text-xs" :style="row.hasPaid ? { color: 'var(--text-muted)' } : { color: 'var(--color-warn-600)' }">
                            Fee: {{ row.fee }} · {{ row.progress_percent }}% done
                        </p>
                    </button>
                </template>
            </DataTable>
        </div>

        <!-- Enrol a student -->
        <UiModal :open="enrolOpen" title="Enrol a student" :persistent="enrolForm.processing" @close="enrolOpen = false">
            <form id="enrol-form" class="space-y-4" @submit.prevent="submitEnrol">
                <UiFormField label="Student" required :error="enrolForm.errors.student_id">
                    <UiCombobox
                        v-model="enrolForm.student_id"
                        :options="students ?? []"
                        :placeholder="students === null ? 'Loading students…' : 'Search students…'"
                        :disabled="students === null"
                    />
                </UiFormField>

                <UiFormField label="Course" required :error="enrolForm.errors.course_id">
                    <UiCombobox
                        v-model="enrolForm.course_id"
                        :options="courses"
                        placeholder="Search courses…"
                        @update:model-value="enrolForm.batch_id = ''"
                    />
                </UiFormField>

                <UiFormField
                    label="Batch"
                    :error="enrolForm.errors.batch_id"
                    :hint="enrolForm.course_id ? 'Leave it for later if no batch has been chosen yet.' : 'Choose the course first.'"
                >
                    <UiSelect v-model="enrolForm.batch_id" :options="enrolBatches" />
                </UiFormField>

                <UiCheckbox v-if="enrolBatchFull" v-model="enrolForm.over_capacity" label="Add over capacity" />
                <UiCheckbox v-model="enrolForm.waive_fee" label="Waive the fee" />
                <UiCheckbox v-model="enrolForm.notify" label="Email the student (invoice included if there is a fee)" />
            </form>

            <template #footer>
                <UiButton variant="ghost" :disabled="enrolForm.processing" @click="enrolOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="enrol-form" :loading="enrolForm.processing">Enrol</UiButton>
            </template>
        </UiModal>

        <!-- Manage one enrolment -->
        <UiModal
            :open="current !== null"
            :title="current ? `${current.student} · ${current.course}` : ''"
            :description="current ? `${current.batch ?? 'No batch'} · enrolled ${current.enrolled_at}` : ''"
            size="lg"
            @close="closeManage"
        >
            <div v-if="current" class="space-y-6">
                <form class="space-y-3" @submit.prevent="submitStatus">
                    <h3 class="text-sm font-semibold" style="color: var(--text-strong)">Status</h3>
                    <div class="flex flex-wrap items-end gap-2">
                        <UiFormField class="min-w-48 flex-1" :error="statusForm.errors.status">
                            <UiSelect v-model="statusForm.status" :options="statuses" />
                        </UiFormField>
                        <UiButton type="submit" size="sm" :loading="statusForm.processing" :disabled="statusForm.status === current.status">
                            Save status
                        </UiButton>
                    </div>
                    <p v-if="statusForm.status === 'dropped'" class="text-xs" style="color: var(--text-muted)">
                        Dropping gives the seat back and takes them out of the batch group. Their history stays.
                    </p>
                </form>

                <form class="space-y-3" @submit.prevent="submitMove">
                    <h3 class="text-sm font-semibold" style="color: var(--text-strong)">Move to another batch</h3>
                    <p v-if="!moveOptions.length" class="text-sm" style="color: var(--text-muted)">
                        This course has no other batch to move to.
                    </p>
                    <template v-else>
                        <div class="flex flex-wrap items-end gap-2">
                            <UiFormField class="min-w-48 flex-1" :error="moveForm.errors.batch_id">
                                <UiSelect v-model="moveForm.batch_id" :options="moveOptions" placeholder="Choose a batch" />
                            </UiFormField>
                            <UiButton type="submit" size="sm" variant="secondary" :loading="moveForm.processing" :disabled="!moveForm.batch_id">
                                Move
                            </UiButton>
                        </div>
                        <UiCheckbox v-if="moveBatchFull" v-model="moveForm.over_capacity" label="Add over capacity" />
                        <p class="text-xs" style="color: var(--text-muted)">
                            Progress, payments and certificates come with them. Attendance stays with the classes it was taken in.
                        </p>
                    </template>
                </form>

                <div v-if="!current.hasPaid" class="space-y-4">
                    <h3 class="text-sm font-semibold" style="color: var(--text-strong)">Fee</h3>

                    <form
                        v-if="canTakePayments && current.paymentRequestId && current.balance > 0"
                        class="space-y-3"
                        @submit.prevent="submitPayment"
                    >
                        <p class="text-sm" style="color: var(--text-muted)">
                            {{ current.balanceLabel }} is outstanding on {{ current.invoiceNumber }}. Record a payment made
                            outside the gateway. Once the fee is paid in full the course opens.
                        </p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <UiFormField label="Amount (₹)" required :error="payForm.errors.amount">
                                <UiInput v-model="payForm.amount" type="number" step="0.01" min="1" />
                            </UiFormField>
                            <UiFormField label="How it arrived" required :error="payForm.errors.method">
                                <UiSelect
                                    v-model="payForm.method"
                                    :options="[
                                        { value: 'bank transfer', label: 'Bank transfer' },
                                        { value: 'upi', label: 'UPI' },
                                        { value: 'cheque', label: 'Cheque' },
                                        { value: 'cash', label: 'Cash' },
                                    ]"
                                />
                            </UiFormField>
                            <UiFormField label="Reference" :error="payForm.errors.note">
                                <UiInput v-model="payForm.note" placeholder="UTR or cheque number" />
                            </UiFormField>
                        </div>
                        <UiButton type="submit" size="sm" :loading="payForm.processing">Record payment</UiButton>
                    </form>

                    <form class="space-y-3" @submit.prevent="submitWaive">
                        <UiFormField
                            label="Waive the fee instead"
                            hint="Cancels the unpaid invoice and opens the course. The reason goes in the audit log."
                            :error="waiveForm.errors.reason"
                        >
                            <UiTextarea v-model="waiveForm.reason" :rows="2" placeholder="Scholarship agreed with the college" />
                        </UiFormField>
                        <UiButton type="submit" size="sm" variant="secondary" :loading="waiveForm.processing" :disabled="waiveForm.reason.trim().length < 5">
                            Waive fee
                        </UiButton>
                    </form>
                </div>
            </div>

            <template #footer>
                <UiButton variant="ghost" @click="closeManage">Close</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
