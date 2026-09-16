<script setup>
/**
 * One project, as the client sees it.
 *
 * Read only on purpose. A milestone date is a promise they are owed, so
 * changing it is a conversation, not a form field.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft, CheckCircle2, Circle, ExternalLink, FileText, Clock, AlertTriangle,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    project: { type: Object, required: true },
    milestones: { type: Array, default: () => [] },
    updates: { type: Array, default: () => [] },
    documents: { type: Array, default: () => [] },
});
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
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader :title="project.name" :description="project.summary">
                <template #actions>
                    <UiButton href="/client/projects" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All projects
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
                        <dd class="font-medium" :style="project.overdue ? { color: 'var(--color-warn-600)' } : {}">
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
                                            ? 'var(--color-signal-500)'
                                            : milestone.overdue
                                                ? 'var(--color-warn-500)'
                                                : 'var(--border-strong)',
                                        background: 'var(--surface)',
                                    }"
                                />

                                <p class="text-sm font-medium" :class="milestone.complete && 'line-through opacity-70'">
                                    {{ milestone.title }}
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
                                </p>
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
    </AppLayout>
</template>
