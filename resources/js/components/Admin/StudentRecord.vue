<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Award, Download } from 'lucide-vue-next';

import UiCard from '@/components/UI/UiCard.vue';
import UiTabs from '@/components/UI/UiTabs.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

/**
 * One student's learning, on their admin profile: what they are on and how it
 * is going, what they owe, what they hold and what they have been warned about.
 * Results are worked out on the server by the same ResultCard the student sees.
 */
const props = defineProps({
    record: { type: Object, required: true },
    canSeeBilling: { type: Boolean, default: false },
});

const tab = ref('enrolments');

const tabs = computed(() => [
    { value: 'enrolments', label: 'Enrolments', count: props.record.enrolments.length },
    ...(props.canSeeBilling ? [{ value: 'payments', label: 'Payments', count: props.record.payments.length }] : []),
    { value: 'certificates', label: 'Certificates', count: props.record.certificates.length },
    { value: 'warnings', label: 'Warnings', count: props.record.warnings.length },
]);

const statusTones = { active: 'success', on_hold: 'warning', completed: 'brand', dropped: 'neutral' };
const paymentTones = { paid: 'success', pending: 'warning', cancelled: 'neutral', refunded: 'neutral' };
const warningTones = { notice: 'neutral', warning: 'warning', escalation: 'danger' };

const fraction = (part) => (part.total ? `${part.taken ?? part.submitted}/${part.total}` : '—');
const percent = (value) => (value === null || value === undefined ? '—' : `${Math.round(value)}%`);
</script>

<template>
    <UiCard>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-base font-semibold">Learning record</h2>
                <span class="text-xs" style="color: var(--text-muted)">
                    {{ record.counts.active }} active
                    <template v-if="record.counts.unpaid"> · {{ record.counts.unpaid }} with a fee due</template>
                    <template v-if="record.counts.openWarnings"> · {{ record.counts.openWarnings }} open warning{{ record.counts.openWarnings === 1 ? '' : 's' }}</template>
                </span>
            </div>
        </template>

        <UiTabs v-model="tab" :tabs="tabs" />

        <div class="mt-4">
            <!-- Enrolments, with how each one is going -->
            <template v-if="tab === 'enrolments'">
                <UiEmptyState v-if="!record.enrolments.length" title="Not on any course" description="Enrol them from the Enrolments screen." />
                <ul v-else class="space-y-3">
                    <li
                        v-for="item in record.enrolments"
                        :key="item.id"
                        class="rounded-xl border p-4"
                        style="border-color: var(--border-subtle)"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium" style="color: var(--text-strong)">{{ item.course }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">
                                    {{ item.batch ?? 'No batch' }} · enrolled {{ item.enrolledOn }} ·
                                    <span :style="item.hasPaid ? {} : { color: 'var(--color-warn-600)' }">fee {{ item.fee.toLowerCase() }}</span>
                                </p>
                            </div>
                            <UiBadge :tone="statusTones[item.status] ?? 'neutral'" size="sm" dot>{{ item.statusLabel }}</UiBadge>
                        </div>

                        <div class="mt-3 grid gap-3 text-sm sm:grid-cols-5">
                            <div class="sm:col-span-2">
                                <p class="text-xs" style="color: var(--text-muted)">Progress</p>
                                <UiProgress :value="item.progress" size="sm" class="mt-1.5" />
                            </div>
                            <div>
                                <p class="text-xs" style="color: var(--text-muted)">Overall</p>
                                <p class="font-semibold tabular-nums">
                                    {{ percent(item.result.overall) }}
                                    <span v-if="item.result.grade" class="text-xs font-normal" style="color: var(--text-muted)">{{ item.result.grade }}</span>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs" style="color: var(--text-muted)">Attendance</p>
                                <p
                                    class="font-semibold tabular-nums"
                                    :style="item.result.shortOnAttendance ? { color: 'var(--color-danger-600)' } : {}"
                                >
                                    {{ percent(item.result.attendance) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs" style="color: var(--text-muted)">Quizzes · work</p>
                                <p class="font-semibold tabular-nums">
                                    {{ fraction(item.result.quizzes) }} · {{ fraction(item.result.assignments) }}
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                            <p class="text-xs" style="color: var(--text-muted)">
                                <template v-if="item.result.overall === null">Nothing marked yet.</template>
                                <template v-else-if="item.result.shortOnAttendance">
                                    Below the {{ item.result.attendanceFloor }}% attendance floor, so not passing yet.
                                </template>
                                <template v-else-if="item.result.passing">On track: at or above the {{ item.result.passMark }}% pass mark.</template>
                                <template v-else>Below the {{ item.result.passMark }}% pass mark.</template>
                            </p>
                            <UiButton v-if="item.batchId" :href="`/admin/batches/${item.batchId}/run`" variant="ghost" size="xs">
                                Open the batch
                                <template #trailing><ArrowRight class="h-3 w-3" /></template>
                            </UiButton>
                        </div>
                    </li>
                </ul>
            </template>

            <!-- Payments -->
            <template v-else-if="tab === 'payments'">
                <UiEmptyState v-if="!record.payments.length" title="Nothing billed" description="Free courses and waived fees raise nothing." />
                <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="payment in record.payments" :key="payment.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium" style="color: var(--text-strong)">{{ payment.title }}</p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ payment.reference }}<template v-if="payment.invoice"> · {{ payment.invoice }}</template> · raised {{ payment.raisedOn }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-sm tabular-nums">{{ payment.paid }} of {{ payment.total }}</span>
                            <UiBadge :tone="paymentTones[payment.status] ?? 'neutral'" size="sm">{{ payment.status }}</UiBadge>
                            <Link :href="`/admin/billing/${payment.id}`" class="text-xs font-medium hover:underline" style="color: var(--text-link, var(--color-brand-600))">
                                Open
                            </Link>
                        </div>
                    </li>
                </ul>
            </template>

            <!-- Certificates -->
            <template v-else-if="tab === 'certificates'">
                <UiEmptyState v-if="!record.certificates.length" title="No certificates yet" description="They are issued from the batch's credentials screen." />
                <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="certificate in record.certificates" :key="certificate.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                        <div class="flex min-w-0 items-start gap-2.5">
                            <Award class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium" style="color: var(--text-strong)">{{ certificate.title }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">
                                    {{ certificate.number }} · issued {{ certificate.issuedOn }}<template v-if="certificate.grade"> · grade {{ certificate.grade }}</template>
                                </p>
                                <p v-if="certificate.revoked" class="text-xs" style="color: var(--color-danger-600)">
                                    Withdrawn<template v-if="certificate.revokedReason">: {{ certificate.revokedReason }}</template>
                                </p>
                            </div>
                        </div>
                        <UiButton :href="`/admin/certificates/${certificate.id}/download`" :inertia="false" variant="ghost" size="xs">
                            <template #leading><Download class="h-3 w-3" /></template>
                            PDF
                        </UiButton>
                    </li>
                </ul>
            </template>

            <!-- Warnings -->
            <template v-else-if="tab === 'warnings'">
                <UiEmptyState v-if="!record.warnings.length" title="No warnings" description="Nothing has been raised about this student." />
                <ul v-else class="space-y-3">
                    <li
                        v-for="warning in record.warnings"
                        :key="warning.id"
                        class="rounded-xl border p-3.5"
                        style="border-color: var(--border-subtle)"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-2">
                                <UiBadge :tone="warningTones[warning.level] ?? 'neutral'" size="sm">{{ warning.level }}</UiBadge>
                                <span class="text-xs" style="color: var(--text-muted)">{{ warning.batch ?? 'No batch' }} · {{ warning.issuedOn }}</span>
                            </span>
                            <UiBadge :tone="warning.resolved ? 'success' : 'warning'" size="sm" dot>
                                {{ warning.resolved ? 'resolved' : warning.acknowledged ? 'acknowledged' : 'open' }}
                            </UiBadge>
                        </div>
                        <p class="mt-2 text-sm">{{ warning.reason }}</p>
                        <p v-if="warning.privateNote" class="mt-1.5 text-xs" style="color: var(--text-muted)">
                            Private note (never shown to the student): {{ warning.privateNote }}
                        </p>
                    </li>
                </ul>
            </template>
        </div>
    </UiCard>
</template>
