<script setup>
/**
 * Accepting a milestone (CL9).
 *
 * What we said was delivered, listed, and two answers: accept it, or ask
 * for changes and say what. Accepting raises the milestone's invoice, so
 * the page says so before anybody presses the button. Every earlier round
 * stays readable underneath.
 */
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, CheckCircle2, ExternalLink, MessageSquareText, ReceiptIndianRupee } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    milestone: { type: Object, required: true },
    pending: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    invoice: { type: Object, default: null },
    canAnswer: { type: Boolean, default: false },
    /** Feedback reported against it and not yet done (CL13). */
    openFeedback: { type: Array, default: () => [] },
});

const base = `/client/projects/${props.project.id}/milestones/${props.milestone.id}`;

const answering = ref(null);
const acceptForm = useForm({ comments: '', confirm: false });
const changesForm = useForm({ comments: '' });

function accept() {
    acceptForm.post(`${base}/accept`, { preserveScroll: true, onSuccess: () => (answering.value = null) });
}

function askForChanges() {
    changesForm.post(`${base}/reject`, { preserveScroll: true, onSuccess: () => (answering.value = null) });
}

const states = {
    waiting: { tone: 'warning', label: 'Waiting for your answer' },
    changes: { tone: 'neutral', label: 'Changes asked for' },
    accepted: { tone: 'success', label: 'Accepted' },
};
</script>

<template>
    <Head :title="milestone.title" />

    <AppLayout
        :title="milestone.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: project.code, href: `/client/projects/${project.id}` },
            { label: 'Milestone' },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="milestone.title" :description="`${project.name}${milestone.dueDate ? ` · due ${milestone.dueDate}` : ''}`">
                <template #actions>
                    <UiButton :href="`/client/projects/${project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge v-if="milestone.state" :tone="states[milestone.state].tone" dot>{{ states[milestone.state].label }}</UiBadge>
                    <span v-if="milestone.payment" style="color: var(--text-muted)">{{ milestone.payment }} before tax</span>
                    <span v-if="milestone.acceptedAt" style="color: var(--text-muted)">· accepted {{ milestone.acceptedAt }}</span>
                </div>
                <p v-if="milestone.description" class="mt-3 whitespace-pre-line text-sm">{{ milestone.description }}</p>
                <p v-if="invoice" class="mt-3 flex items-center gap-2 text-sm">
                    <ReceiptIndianRupee class="h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                    <span>
                        Its invoice, {{ invoice.total }},
                        <a :href="`/client/payments/${invoice.id}`" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ invoice.status === 'paid' ? 'is paid' : 'is ready to pay' }}</a>.
                    </span>
                </p>
            </UiCard>

            <!-- ------------------------------------------- waiting for them -->
            <UiCard v-if="pending">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">What was delivered</h2>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">Sent {{ pending.requestedAt }}<template v-if="pending.requestedBy"> by {{ pending.requestedBy }}</template></p>

                <ul class="mt-4 space-y-2">
                    <li v-for="(item, index) in pending.deliverables" :key="index" class="flex items-start gap-2.5 text-sm">
                        <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0" style="color: var(--color-signal-500)" aria-hidden="true" />
                        <span>{{ item }}</span>
                    </li>
                </ul>

                <p v-if="pending.note" class="mt-4 whitespace-pre-line rounded-xl p-3 text-sm" style="background: var(--surface-sunken)">{{ pending.note }}</p>
                <a
                    v-if="project.stagingUrl"
                    :href="project.stagingUrl"
                    target="_blank"
                    rel="noopener"
                    class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                >
                    Open the staging site <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
                </a>

                <div v-if="canAnswer" class="mt-5 flex flex-wrap gap-2 border-t pt-4" style="border-color: var(--border-subtle)">
                    <UiButton @click="answering = 'accept'">
                        <template #leading><BadgeCheck class="h-4 w-4" /></template>
                        Accept the milestone
                    </UiButton>
                    <UiButton variant="secondary" @click="answering = 'changes'">
                        <template #leading><MessageSquareText class="h-4 w-4" /></template>
                        Ask for changes
                    </UiButton>
                </div>
                <p v-else class="mt-5 border-t pt-4 text-sm" style="border-color: var(--border-subtle); color: var(--text-muted)">
                    An authorised signatory or an owner in your organisation accepts milestones. It has been sent to them.
                </p>
            </UiCard>

            <UiCard v-if="openFeedback.length">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Feedback still open on it</h2>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">Reported against this milestone and not yet done.</p>
                <ul class="mt-3 space-y-1.5 text-sm">
                    <li v-for="item in openFeedback" :key="item.id">
                        <a :href="`/client/projects/${project.id}/feedback/${item.id}`" class="font-medium text-brand-600 hover:underline dark:text-brand-400">{{ item.title }}</a>
                        <span style="color: var(--text-muted)"> · {{ item.statusLabel }}</span>
                    </li>
                </ul>
            </UiCard>

            <!-- --------------------------------------------- earlier rounds -->
            <UiCard v-if="history.length">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Answers so far</h2>
                <ol class="mt-4 space-y-4">
                    <li v-for="round in history" :key="round.id" class="border-l-2 pl-3" :style="{ borderColor: round.state === 'accepted' ? 'var(--color-signal-500)' : 'var(--border-strong)' }">
                        <p class="text-sm font-medium">
                            {{ round.state === 'accepted' ? 'Accepted' : 'Changes asked for' }}
                            <span class="font-normal" style="color: var(--text-muted)">by {{ round.decidedBy }}, {{ round.decidedAt }}</span>
                        </p>
                        <p v-if="round.comments" class="mt-1 whitespace-pre-line text-sm">“{{ round.comments }}”</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">Delivered as listed on {{ round.requestedAt }}: {{ round.deliverables.join('; ') }}.</p>
                    </li>
                </ol>
            </UiCard>

            <UiCard v-if="!pending && !history.length">
                <p class="text-sm" style="color: var(--text-muted)">We have not asked you to accept this milestone yet.</p>
            </UiCard>
        </div>

        <UiModal
            :open="answering === 'accept'"
            title="Accept this milestone?"
            :description="milestone.payment
                ? `This records that the work listed was delivered, and raises its invoice of ${milestone.payment} before tax, sent to your finance contacts.`
                : 'This records that the work listed was delivered.'"
            @close="answering = null"
        >
            <form id="accept-form" class="space-y-4" @submit.prevent="accept">
                <UiFormField label="Anything to add" hint="Optional." :error="acceptForm.errors.comments">
                    <UiTextarea v-model="acceptForm.comments" :rows="3" />
                </UiFormField>
                <div>
                    <UiCheckbox v-model="acceptForm.confirm" label="The work listed was delivered, and I accept it for my organisation." />
                    <p v-if="acceptForm.errors.confirm" class="mt-1 text-xs font-medium text-danger-500">{{ acceptForm.errors.confirm }}</p>
                </div>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="answering = null">Cancel</UiButton>
                <UiButton type="submit" form="accept-form" :loading="acceptForm.processing" :disabled="!acceptForm.confirm">Accept</UiButton>
            </template>
        </UiModal>

        <UiModal
            :open="answering === 'changes'"
            title="Ask for changes"
            description="Tell us what is not right. We will put it right and ask you again."
            @close="answering = null"
        >
            <form id="changes-form" @submit.prevent="askForChanges">
                <UiFormField label="What needs changing" :error="changesForm.errors.comments" required>
                    <UiTextarea v-model="changesForm.comments" :rows="5" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="answering = null">Cancel</UiButton>
                <UiButton type="submit" form="changes-form" :loading="changesForm.processing" :disabled="changesForm.comments.trim().length < 10">Send</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
