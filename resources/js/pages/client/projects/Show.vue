<script setup>
/**
 * One project, as the client sees it.
 *
 * Read only on purpose. A milestone date is a promise they are owed, so
 * changing it is a conversation, not a form field. The one thing asked of
 * them here is accepting a milestone, which opens on its own page (CL9).
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, CheckCircle2, Circle, ExternalLink, FileText, Clock, AlertTriangle,
    FileDown, Inbox, ArrowRight, FilePlus2, Bug,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    /** What we need from them to carry on (CL10). */
    waiting: { type: Array, default: () => [] },
    waitingTime: { type: String, default: null },
    /** Changes to the scope (CL12). */
    changes: { type: Array, default: () => [] },
    /** Feedback on staging (CL13): counts and whether this person may report. */
    feedback: { type: Object, default: () => ({ open: 0, toCheck: 0, total: 0, canReport: false }) },
    /** The handover pack, once published (CL15). */
    handover: { type: Object, default: null },
    milestones: { type: Array, default: () => [] },
    updates: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
});

/* Asking for a change to the scope (CL12). */
const asking = ref(false);
const changeForm = useForm({ title: '', description: '' });
const changeTones = { requested: 'warning', quoted: 'brand', approved: 'success', declined: 'danger', withdrawn: 'neutral' };

function askForChange() {
    changeForm.post(`/client/projects/${props.project.id}/changes`, { onSuccess: () => { asking.value = false; changeForm.reset(); } });
}
</script>

<template>
    <Head :title="project.name" />

    <AppLayout
        :title="project.name"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: project.code },
        ]"
    >
        <div class="space-y-5">
            <PageHeader :title="project.name" :description="project.summary">
                <template #actions>
                    <UiButton href="/client/projects" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All projects
                    </UiButton>
                    <UiButton :href="`/client/projects/${project.id}/status-report`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><FileDown class="h-3.5 w-3.5" /></template>
                        Status report
                    </UiButton>
                    <UiButton
                        v-if="project.stagingUrl"
                        :href="project.stagingUrl"
                        :inertia="false"
                        target="_blank"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                </template>
            </PageHeader>

            <!-- --------------------------------------------------- progress -->
            <UiCard>
                <div class="flex flex-wrap items-center gap-3">
                    <UiBadge size="sm" dot>{{ project.statusLabel }}</UiBadge>
                    <UiBadge v-if="project.phase" tone="brand" size="sm">{{ project.phase }}</UiBadge>
                    <UiBadge v-if="project.overdue" tone="warning" size="sm">
                        <AlertTriangle class="mr-1 h-3 w-3" />
                        past target date
                    </UiBadge>
                </div>

                <div class="mt-4">
                    <UiProgress :value="project.progress" label="Overall progress" size="lg" />
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-4">
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Started</dt>
                        <dd class="font-medium">{{ project.startDate || 'Not set' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Target</dt>
                        <dd class="font-medium" :style="project.overdue ? { color: 'var(--text-warning)' } : {}">
                            {{ project.targetDate || 'Not set' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs" style="color: var(--text-muted)">Your contact</dt>
                        <dd class="font-medium">{{ project.manager || 'The team' }}</dd>
                    </div>
                    <div v-if="project.budget">
                        <dt class="text-xs" style="color: var(--text-muted)">Agreed value</dt>
                        <dd class="font-medium tnum">{{ project.budget }}</dd>
                    </div>
                </dl>
            </UiCard>

            <!-- ------------------------------------------- waiting on you -->
            <UiCard v-if="waiting.length || waitingTime">
                <template #header>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-base font-semibold">Waiting on you</h2>
                        <span v-if="waitingTime" class="text-xs" style="color: var(--text-muted)">
                            Work on this project has waited {{ waitingTime }} in all on things only you could give us.
                        </span>
                    </div>
                </template>

                <p v-if="!waiting.length" class="text-sm" style="color: var(--text-muted)">Nothing is waiting on you right now.</p>
                <ul v-else class="divide-y">
                    <li v-for="item in waiting" :key="item.id">
                        <Link :href="`/client/projects/${project.id}/waiting/${item.id}`" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <Inbox class="h-4 w-4 shrink-0" :style="{ color: item.overdue ? 'var(--text-warning)' : 'var(--text-muted)' }" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ item.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ item.kindLabel }}<template v-if="item.dueOn"> · {{ item.overdue ? 'was wanted by' : 'wanted by' }} {{ item.dueOn }}</template>
                                </span>
                            </span>
                            <UiBadge v-if="item.status === 'answered'" tone="brand" size="sm" dot>Answered</UiBadge>
                            <span v-else class="flex shrink-0 items-center gap-1 text-xs font-medium" style="color: var(--text-brand)">
                                Reply <ArrowRight class="h-3 w-3" />
                            </span>
                        </Link>
                    </li>
                </ul>
            </UiCard>

            <!-- -------------------------------------------- handover pack -->
            <UiCard v-if="handover">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold">Handover pack</p>
                        <p class="text-xs" style="color: var(--text-muted)">Where the code is, deploying it, licences, training and the warranty.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <UiBadge v-if="!handover.acknowledged" tone="warning" size="sm" dot>To acknowledge</UiBadge>
                        <UiButton :href="`/client/projects/${project.id}/handover`" size="xs" variant="secondary">Open</UiButton>
                    </div>
                </div>
            </UiCard>

            <!-- --------------------------------------- feedback on staging -->
            <UiCard v-if="feedback.total || feedback.canReport">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <Bug class="h-5 w-5 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                        <div>
                            <p class="text-sm font-semibold">Feedback on staging</p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                <template v-if="feedback.total">{{ feedback.open }} open<template v-if="feedback.toCheck"> · {{ feedback.toCheck }} fixed, waiting for you to check</template></template>
                                <template v-else>Found something on the preview? Report it with a screenshot.</template>
                            </p>
                        </div>
                    </div>
                    <UiButton :href="`/client/projects/${project.id}/feedback`" size="xs" variant="secondary">
                        {{ feedback.canReport ? 'Report or follow up' : 'See it' }}
                    </UiButton>
                </div>
            </UiCard>

            <!-- ------------------------------------------ scope changes -->
            <UiCard>
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">Changes to the scope</h2>
                        <UiButton size="xs" variant="secondary" @click="asking = true">
                            <template #leading><FilePlus2 class="h-3 w-3" /></template>
                            Ask for a change
                        </UiButton>
                    </div>
                </template>
                <p v-if="!changes.length" class="text-sm" style="color: var(--text-muted)">Need something the plan does not cover? Ask here: we estimate it, and nothing changes until you approve.</p>
                <ul v-else class="divide-y">
                    <li v-for="change in changes" :key="change.id">
                        <Link :href="`/client/projects/${project.id}/changes/${change.id}`" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ change.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ change.number }}<template v-if="change.amount"> · {{ change.amount }} before tax</template></span>
                            </span>
                            <UiBadge :tone="changeTones[change.status] ?? 'neutral'" size="sm" dot>{{ change.statusLabel }}</UiBadge>
                        </Link>
                    </li>
                </ul>
            </UiCard>

            <div class="grid gap-5 lg:grid-cols-5">
                <!-- ------------------------------------------- milestones -->
                <div class="lg:col-span-2 space-y-5">
                    <UiCard>
                        <template #header>
                            <h2 class="text-base font-semibold">Milestones</h2>
                        </template>

                        <UiEmptyState
                            v-if="!milestones.length"
                            :icon="Circle"
                            title="No milestones set"
                            description="We will break the work down here as it is planned."
                        />

                        <ol v-else class="relative space-y-5">
                            <li
                                v-for="(milestone, index) in milestones"
                                :key="milestone.id"
                                class="relative pl-7"
                            >
                                <span
                                    v-if="index < milestones.length - 1"
                                    class="absolute left-[9px] top-6 h-full w-px"
                                    style="background: var(--border-subtle)"
                                />

                                <component
                                    :is="milestone.complete ? CheckCircle2 : Circle"
                                    class="absolute left-0 top-0.5 h-[18px] w-[18px]"
                                    :style="{
                                        color: milestone.complete
                                            ? 'var(--text-success)'
                                            : milestone.overdue
                                                ? 'var(--color-warn-500)'
                                                : 'var(--border-strong)',
                                        background: 'var(--surface)',
                                    }"
                                />

                                <p class="text-sm font-medium" :class="milestone.complete && !milestone.signoff && 'line-through opacity-70'">
                                    <Link
                                        v-if="milestone.signoff"
                                        :href="`/client/projects/${project.id}/milestones/${milestone.id}`"
                                        class="hover:underline"
                                    >{{ milestone.title }}</Link>
                                    <template v-else>{{ milestone.title }}</template>
                                </p>
                                <p v-if="milestone.description" class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                    {{ milestone.description }}
                                </p>
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                                    <span v-if="milestone.complete">Done {{ milestone.completedAt }}</span>
                                    <span v-else-if="milestone.dueDate">
                                        <Clock class="mr-0.5 inline h-3 w-3" />Due {{ milestone.dueDate }}
                                    </span>
                                    <UiBadge v-if="milestone.overdue" tone="warning" size="sm">overdue</UiBadge>
                                    <UiBadge v-if="milestone.payment" size="sm">{{ milestone.payment }}</UiBadge>
                                    <UiBadge v-if="milestone.signoff === 'accepted'" tone="success" size="sm" dot>Accepted {{ milestone.acceptedAt }}</UiBadge>
                                    <UiBadge v-else-if="milestone.signoff === 'changes'" tone="neutral" size="sm" dot>Changes asked for</UiBadge>
                                </p>
                                <UiButton
                                    v-if="milestone.signoff === 'waiting'"
                                    :href="`/client/projects/${project.id}/milestones/${milestone.id}`"
                                    size="xs"
                                    class="mt-2"
                                >
                                    Review and accept
                                </UiButton>
                            </li>
                        </ol>
                    </UiCard>

                    <UiCard v-if="documents.length">
                        <template #header>
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="text-base font-semibold">Documents</h2>
                                <UiButton href="/client/documents" variant="ghost" size="xs">All</UiButton>
                            </div>
                        </template>

                        <ul class="divide-y" style="border-color: var(--border-subtle)">
                            <li v-for="document in documents" :key="document.id" class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                                <FileText class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{ document.name }}</span>
                                    <span class="block text-xs" style="color: var(--text-muted)">
                                        {{ document.size }} · {{ document.at }}
                                    </span>
                                </span>
                                <UiButton
                                    :href="`/client/documents/${document.id}/download`"
                                    :inertia="false"
                                    variant="ghost"
                                    size="xs"
                                >
                                    Download
                                </UiButton>
                            </li>
                        </ul>
                    </UiCard>
                </div>

                <!-- ---------------------------------------------- timeline -->
                <div class="lg:col-span-3">
                    <UiCard>
                        <template #header>
                            <h2 class="text-base font-semibold">What has been happening</h2>
                        </template>

                        <UiEmptyState
                            v-if="!updates.length"
                            title="No updates yet"
                            description="The team posts progress notes here as the work moves."
                        />

                        <ol v-else class="space-y-5">
                            <li v-for="update in updates" :key="update.id" class="relative pl-5">
                                <span
                                    class="absolute left-0 top-2 h-2 w-2 rounded-full"
                                    style="background: var(--color-brand-500)"
                                />
                                <p v-if="update.title" class="text-sm font-semibold">{{ update.title }}</p>
                                <p class="mt-0.5 whitespace-pre-line text-sm" style="color: var(--text-base)">
                                    {{ update.body }}
                                </p>
                                <p class="mt-1.5 text-xs" style="color: var(--text-muted)">
                                    {{ update.author }} · {{ update.at }}
                                </p>
                            </li>
                        </ol>
                    </UiCard>
                </div>
            </div>
        </div>
        <UiModal :open="asking" title="Ask for a change" description="Describe what you need. We estimate it and send it to your signatories; nothing changes until it is approved." @close="asking = false">
            <form id="change-form" class="space-y-4" @submit.prevent="askForChange">
                <UiFormField label="The change" required :error="changeForm.errors.title">
                    <UiInput v-model="changeForm.title" maxlength="160" />
                </UiFormField>
                <UiFormField label="What you need, and why" required :error="changeForm.errors.description">
                    <UiTextarea v-model="changeForm.description" :rows="6" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="asking = false">Cancel</UiButton>
                <UiButton type="submit" form="change-form" :loading="changeForm.processing" :disabled="!changeForm.title.trim() || !changeForm.description.trim()">Send</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
