<script setup>
/**
 * Running one project.
 *
 * The visibility switch on an update is the point of this screen: a delivery
 * team needs somewhere to write "their API is down again" that is not the
 * client's timeline.
 */
import { computed, ref } from 'vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import {
    ArrowLeft, Pencil, Plus, Trash2, CheckCircle2, Circle, EyeOff, Eye, Send,
    FileDown, ClipboardCheck, Undo2,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';

const props = defineProps({
    project: { type: Object, required: true },
    milestones: { type: Array, default: () => [] },
    updates: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

/* ------------------------------------------------------------- milestones */

const editingMilestone = ref(null);
const milestoneOpen = ref(false);

const milestoneForm = useForm({
    title: '',
    description: '',
    due_date: '',
    progress_percent: 0,
    payment_amount: null,
});

function openMilestone(milestone = null) {
    editingMilestone.value = milestone;
    milestoneForm.clearErrors();
    milestoneForm.title = milestone?.title ?? '';
    milestoneForm.description = milestone?.description ?? '';
    milestoneForm.due_date = milestone?.dueDate ?? '';
    milestoneForm.progress_percent = milestone?.progress ?? 0;
    milestoneForm.payment_amount = milestone?.paymentValue ?? null;
    milestoneOpen.value = true;
}

function saveMilestone() {
    const done = { preserveScroll: true, onSuccess: () => closeMilestone() };

    editingMilestone.value
        ? milestoneForm.put(`/admin/projects/${props.project.id}/milestones/${editingMilestone.value.id}`, done)
        : milestoneForm.post(`/admin/projects/${props.project.id}/milestones`, done);
}

function closeMilestone() {
    milestoneOpen.value = false;
    editingMilestone.value = null;
    milestoneForm.reset();
}

function toggleMilestone(milestone) {
    router.post(`/admin/projects/${props.project.id}/milestones/${milestone.id}/complete`, {}, { preserveScroll: true });
}

function deleteMilestone(milestone) {
    if (confirm(`Remove "${milestone.title}"?`)) {
        router.delete(`/admin/projects/${props.project.id}/milestones/${milestone.id}`, { preserveScroll: true });
    }
}

/* ----------------------------------------- acceptance by the client (CL9) */

const signoffMilestone = ref(null);
const signoffForm = useForm({ deliverables: [], note: '' });

const signoffTones = { waiting: 'warning', changes: 'danger', accepted: 'success' };

const deliverablesError = computed(() => signoffForm.errors.deliverables
    ?? Object.entries(signoffForm.errors).find(([key]) => key.startsWith('deliverables.'))?.[1]);

function signoffLabel(milestone) {
    return {
        waiting: 'Waiting for the client',
        changes: 'Changes asked for',
        accepted: `Accepted ${milestone.acceptedAt ?? ''}`.trim(),
    }[milestone.signoff];
}

function lastAnswer(milestone) {
    return [...milestone.signoffs].reverse().find((signoff) => signoff.state === 'changes' || signoff.state === 'accepted') ?? null;
}

function openSignoff(milestone) {
    signoffMilestone.value = milestone;
    signoffForm.clearErrors();
    signoffForm.deliverables = [...(milestone.lastDeliverables ?? [])];
    signoffForm.note = '';
}

function submitSignoff() {
    signoffForm.post(`/admin/projects/${props.project.id}/milestones/${signoffMilestone.value.id}/signoff`, {
        preserveScroll: true,
        onSuccess: () => (signoffMilestone.value = null),
    });
}

function withdrawSignoff(milestone) {
    if (confirm(`Withdraw the request to accept "${milestone.title}"? The client can no longer answer it.`)) {
        router.post(`/admin/projects/${props.project.id}/milestones/${milestone.id}/signoff/withdraw`, {}, { preserveScroll: true });
    }
}

/* ---------------------------------------------------------------- updates */

const updateForm = useForm({
    title: '',
    body: '',
    visible_to_client: true,
    notify: false,
});

function postUpdate() {
    updateForm.post(`/admin/projects/${props.project.id}/updates`, {
        preserveScroll: true,
        onSuccess: () => updateForm.reset(),
    });
}

function deleteUpdate(update) {
    if (confirm('Remove this update?')) {
        router.delete(`/admin/projects/${props.project.id}/updates/${update.id}`, { preserveScroll: true });
    }
}

/* ----------------------------------------------------------------- status */

function setStatus(status) {
    router.put(`/admin/projects/${props.project.id}`, { ...props.project, status }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="project.name" />

    <AppLayout
        :title="project.name"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Projects', href: '/admin/projects' },
            { label: project.code },
        ]"
    >
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader :title="project.name" :description="project.summary">
                <template #actions>
                    <UiButton href="/admin/projects" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All projects
                    </UiButton>
                    <UiButton :href="`/admin/projects/${project.id}/status-report`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><FileDown class="h-3.5 w-3.5" /></template>
                        Status report
                    </UiButton>
                    <UiButton :href="`/admin/projects/${project.id}/edit`" variant="secondary" size="sm">
                        <template #leading><Pencil class="h-3.5 w-3.5" /></template>
                        Edit
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3">
                    <UiBadge size="sm" dot>{{ project.statusLabel }}</UiBadge>
                    <UiBadge v-if="project.phase" tone="brand" size="sm">{{ project.phase }}</UiBadge>
                    <UiBadge v-if="project.overdue" tone="warning" size="sm">past target</UiBadge>
                    <Link
                        :href="`/admin/clients/${project.client.id}`"
                        class="text-sm hover:underline"
                        style="color: var(--color-brand-600)"
                    >
                        {{ project.client.name }}
                    </Link>
                </div>

                <div class="mt-4">
                    <UiProgress :value="project.progress" label="Progress" size="lg" />
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Started</dt>
                        <dd class="font-medium">{{ project.startDate || '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Target</dt>
                        <dd class="font-medium" :style="project.overdue ? { color: 'var(--color-warn-600)' } : {}">
                            {{ project.targetDate || '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Lead</dt>
                        <dd class="font-medium">{{ project.manager || 'Unassigned' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Value</dt>
                        <dd class="font-medium tnum">{{ project.budget || '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap gap-1.5 border-t pt-4" style="border-color: var(--border-subtle)">
                    <UiButton
                        v-for="status in statuses"
                        :key="status"
                        :variant="project.status === status ? 'primary' : 'ghost'"
                        size="xs"
                        @click="setStatus(status)"
                    >
                        {{ status.replace('_', ' ') }}
                    </UiButton>
                </div>
            </UiCard>

            <div class="grid gap-5 lg:grid-cols-5">
                <!-- ------------------------------------------- milestones -->
                <div class="lg:col-span-2">
                    <UiCard>
                        <template #header>
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-base font-semibold">Milestones</h2>
                                <UiButton size="xs" @click="openMilestone()">
                                    <template #leading><Plus class="h-3 w-3" /></template>
                                    Add
                                </UiButton>
                            </div>
                        </template>

                        <UiEmptyState
                            v-if="!milestones.length"
                            :icon="Circle"
                            title="No milestones"
                            description="Break the work down so the client can see it moving."
                        />

                        <ul v-else class="space-y-3">
                            <li v-for="milestone in milestones" :key="milestone.id" class="flex items-start gap-2.5">
                                <button
                                    type="button"
                                    class="mt-0.5 shrink-0 disabled:cursor-default"
                                    :aria-label="milestone.complete ? 'Reopen milestone' : 'Mark done'"
                                    :disabled="Boolean(milestone.acceptedAt)"
                                    @click="toggleMilestone(milestone)"
                                >
                                    <component
                                        :is="milestone.complete ? CheckCircle2 : Circle"
                                        class="h-[18px] w-[18px]"
                                        :style="{
                                            color: milestone.complete
                                                ? 'var(--color-signal-500)'
                                                : milestone.overdue
                                                    ? 'var(--color-warn-500)'
                                                    : 'var(--border-strong)',
                                        }"
                                    />
                                </button>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium" :class="milestone.complete && 'line-through opacity-70'">
                                        {{ milestone.title }}
                                    </p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                                        <span v-if="milestone.complete">Done {{ milestone.completedAt }}</span>
                                        <span v-else-if="milestone.dueDateLabel">Due {{ milestone.dueDateLabel }}</span>
                                        <UiBadge v-if="milestone.overdue" tone="warning" size="sm">overdue</UiBadge>
                                        <UiBadge v-if="milestone.payment" size="sm">{{ milestone.payment }}</UiBadge>
                                        <UiBadge v-if="milestone.signoff" :tone="signoffTones[milestone.signoff]" size="sm" dot>{{ signoffLabel(milestone) }}</UiBadge>
                                    </p>
                                    <p v-if="lastAnswer(milestone)?.comments" class="mt-1 whitespace-pre-line text-xs" style="color: var(--text-base)">
                                        “{{ lastAnswer(milestone).comments }}” <span style="color: var(--text-muted)">· {{ lastAnswer(milestone).decidedBy }}, {{ lastAnswer(milestone).decidedAt }}</span>
                                    </p>
                                    <details v-if="milestone.signoffs.length" class="mt-1 text-xs" style="color: var(--text-muted)">
                                        <summary class="cursor-pointer select-none">Acceptance history ({{ milestone.signoffs.length }})</summary>
                                        <ol class="mt-1.5 space-y-1.5 border-l pl-3" style="border-color: var(--border-subtle)">
                                            <li v-for="round in milestone.signoffs" :key="round.id">
                                                Asked {{ round.requestedAt }}<template v-if="round.requestedBy"> by {{ round.requestedBy }}</template>:
                                                {{ round.deliverables.join('; ') }}.
                                                <template v-if="round.state === 'withdrawn'"> Withdrawn {{ round.withdrawnAt }}.</template>
                                                <template v-else-if="round.state === 'waiting'"> Waiting.</template>
                                                <template v-else> {{ round.state === 'accepted' ? 'Accepted' : 'Changes asked for' }} by {{ round.decidedBy }}, {{ round.decidedAt }}.</template>
                                            </li>
                                        </ol>
                                    </details>
                                </div>

                                <div class="flex shrink-0 gap-0.5">
                                    <UiButton
                                        v-if="!milestone.acceptedAt && milestone.signoff !== 'waiting'"
                                        variant="ghost"
                                        size="xs"
                                        icon
                                        aria-label="Ask the client to accept"
                                        title="Ask the client to accept"
                                        @click="openSignoff(milestone)"
                                    >
                                        <ClipboardCheck class="h-3 w-3" />
                                    </UiButton>
                                    <UiButton
                                        v-if="milestone.signoff === 'waiting'"
                                        variant="ghost"
                                        size="xs"
                                        icon
                                        aria-label="Withdraw the request to accept"
                                        title="Withdraw the request to accept"
                                        @click="withdrawSignoff(milestone)"
                                    >
                                        <Undo2 class="h-3 w-3" />
                                    </UiButton>
                                    <template v-if="!milestone.acceptedAt">
                                        <UiButton variant="ghost" size="xs" icon aria-label="Edit" @click="openMilestone(milestone)">
                                            <Pencil class="h-3 w-3" />
                                        </UiButton>
                                        <UiButton variant="ghost" size="xs" icon aria-label="Remove" @click="deleteMilestone(milestone)">
                                            <Trash2 class="h-3 w-3" style="color: var(--color-danger-500)" />
                                        </UiButton>
                                    </template>
                                </div>
                            </li>
                        </ul>
                    </UiCard>
                </div>

                <!-- ---------------------------------------------- timeline -->
                <div class="lg:col-span-3 space-y-5">
                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Post an update</h2></template>

                        <form class="space-y-3" @submit.prevent="postUpdate">
                            <UiFormField label="Headline" :error="updateForm.errors.title">
                                <UiInput v-model="updateForm.title" placeholder="Reporting module is on staging" />
                            </UiFormField>

                            <UiFormField label="What happened" required :error="updateForm.errors.body">
                                <UiTextarea v-model="updateForm.body" :rows="4" />
                            </UiFormField>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="space-y-2">
                                    <UiSwitch
                                        v-model="updateForm.visible_to_client"
                                        label="The client can see this"
                                        description="Off makes it an internal note."
                                    />
                                    <UiSwitch
                                        v-if="updateForm.visible_to_client"
                                        v-model="updateForm.notify"
                                        label="Email it to them"
                                    />
                                </div>

                                <UiButton type="submit" size="sm" :loading="updateForm.processing" :disabled="!updateForm.body.trim()">
                                    <template #leading><Send class="h-3.5 w-3.5" /></template>
                                    Post
                                </UiButton>
                            </div>
                        </form>
                    </UiCard>

                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Timeline</h2></template>

                        <UiEmptyState v-if="!updates.length" title="Nothing posted yet" />

                        <ol v-else class="space-y-4">
                            <li v-for="update in updates" :key="update.id" class="group relative pl-5">
                                <span
                                    class="absolute left-0 top-2 h-2 w-2 rounded-full"
                                    :style="{
                                        background: update.visibleToClient
                                            ? 'var(--color-brand-500)'
                                            : 'var(--border-strong)',
                                    }"
                                />

                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <p v-if="update.title" class="text-sm font-semibold">{{ update.title }}</p>
                                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ update.body }}</p>
                                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                                            <span>{{ update.author }} · {{ update.at }}</span>
                                            <UiBadge v-if="!update.visibleToClient" size="sm">
                                                <EyeOff class="mr-1 h-3 w-3" />internal
                                            </UiBadge>
                                            <UiBadge v-else tone="brand" size="sm">
                                                <Eye class="mr-1 h-3 w-3" />visible
                                            </UiBadge>
                                        </p>
                                    </div>

                                    <UiButton
                                        variant="ghost"
                                        size="xs"
                                        icon
                                        class="opacity-0 transition group-hover:opacity-100"
                                        aria-label="Remove update"
                                        @click="deleteUpdate(update)"
                                    >
                                        <Trash2 class="h-3 w-3" style="color: var(--color-danger-500)" />
                                    </UiButton>
                                </div>
                            </li>
                        </ol>
                    </UiCard>
                </div>
            </div>
        </div>

        <UiModal
            :open="milestoneOpen"
            :title="editingMilestone ? 'Edit milestone' : 'Add a milestone'"
            @close="closeMilestone"
        >
            <form id="milestone-form" class="space-y-4" @submit.prevent="saveMilestone">
                <UiFormField label="Title" required :error="milestoneForm.errors.title">
                    <UiInput v-model="milestoneForm.title" placeholder="Design sign off" />
                </UiFormField>

                <UiFormField label="Description" :error="milestoneForm.errors.description">
                    <UiTextarea v-model="milestoneForm.description" :rows="3" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Due" :error="milestoneForm.errors.due_date">
                        <UiDateInput v-model="milestoneForm.due_date" />
                    </UiFormField>

                    <UiFormField label="Progress (%)" :error="milestoneForm.errors.progress_percent">
                        <UiInput v-model="milestoneForm.progress_percent" type="number" min="0" max="100" />
                    </UiFormField>

                    <UiFormField
                        label="Payment (₹)"
                        :error="milestoneForm.errors.payment_amount"
                        hint="Before tax. Invoiced when the client accepts the milestone."
                    >
                        <UiInput v-model="milestoneForm.payment_amount" type="number" step="0.01" min="0" />
                    </UiFormField>
                </div>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="closeMilestone">Cancel</UiButton>
                <UiButton type="submit" form="milestone-form" :loading="milestoneForm.processing">
                    {{ editingMilestone ? 'Save' : 'Add milestone' }}
                </UiButton>
            </template>
        </UiModal>

        <UiModal
            :open="Boolean(signoffMilestone)"
            title="Ask the client to accept"
            :description="signoffMilestone
                ? `Their signatories and owners are sent this list for ${signoffMilestone.title}.${signoffMilestone.payment ? ` Accepting raises its invoice of ${signoffMilestone.payment} before tax.` : ''}`
                : ''"
            @close="signoffMilestone = null"
        >
            <form id="signoff-form" class="space-y-4" @submit.prevent="submitSignoff">
                <UiFormField label="What was delivered" hint="One line each. This is what they accept." :error="deliverablesError" required>
                    <UiRepeater v-model="signoffForm.deliverables" :max="20" placeholder="Type a deliverable and press Enter" />
                </UiFormField>
                <UiFormField label="Note for them" hint="Optional: where to check it, such as the staging address." :error="signoffForm.errors.note">
                    <UiTextarea v-model="signoffForm.note" :rows="3" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="signoffMilestone = null">Cancel</UiButton>
                <UiButton type="submit" form="signoff-form" :loading="signoffForm.processing" :disabled="!signoffForm.deliverables.length">
                    <template #leading><Send class="h-3.5 w-3.5" /></template>
                    Send to the client
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
