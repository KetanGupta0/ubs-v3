<script setup>
/**
 * Everything waiting to be marked, in one queue.
 *
 * Written quiz answers and assignment submissions together, because the person
 * marking thinks "what is waiting for me", not "which quiz shall I open".
 */
import { Head } from '@inertiajs/vue3';
import { CheckCheck, FileText, ClipboardList, Clock } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    submissions: { type: Array, default: () => [] },
    attempts: { type: Array, default: () => [] },
});

const waiting = (days) => (days >= 1 ? `waiting ${days} day${days === 1 ? '' : 's'}` : 'waiting since today');

const tone = (days) => (days >= 7 ? 'danger' : days >= 3 ? 'warning' : 'neutral');
</script>

<template>
    <Head title="Marking" />

    <AppLayout title="Marking" :breadcrumbs="[{ label: 'Dashboard', href: '/admin' }, { label: 'Marking' }]">
        <div class="mx-auto max-w-4xl space-y-6">
            <PageHeader
                title="Waiting to be marked"
                description="Oldest first. A mark that arrives three weeks later is not feedback, it is a receipt."
            >
                <template #actions>
                    <UiBadge tone="brand">{{ submissions.length + attempts.length }} in the queue</UiBadge>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!submissions.length && !attempts.length"
                :icon="CheckCheck"
                title="Nothing waiting"
                description="Everything handed in has been marked."
            />

            <!-- ------------------------------------------- submissions -->
            <section v-if="submissions.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Assignments
                </h2>

                <UiCard padding="p-0">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="submission in submissions"
                            :key="submission.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        >
                            <FileText class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ submission.student }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ submission.assignment }} · {{ submission.course }} ·
                                    handed in {{ submission.submittedAt }}
                                </span>
                            </span>

                            <UiBadge v-if="submission.late" tone="warning" size="sm">late</UiBadge>
                            <UiBadge :tone="tone(submission.waitingDays)" size="sm">
                                <Clock class="mr-1 h-3 w-3" />{{ waiting(submission.waitingDays) }}
                            </UiBadge>

                            <UiButton :href="`/admin/marking/submissions/${submission.id}`" size="sm">Mark</UiButton>
                        </li>
                    </ul>
                </UiCard>
            </section>

            <!-- ---------------------------------------- written answers -->
            <section v-if="attempts.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Written quiz answers
                </h2>

                <UiCard padding="p-0">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="attempt in attempts"
                            :key="attempt.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        >
                            <ClipboardList class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ attempt.student }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ attempt.quiz }} · {{ attempt.course }} · sat {{ attempt.submittedAt }}
                                </span>
                            </span>

                            <UiBadge :tone="tone(attempt.waitingDays)" size="sm">
                                <Clock class="mr-1 h-3 w-3" />{{ waiting(attempt.waitingDays) }}
                            </UiBadge>

                            <UiButton :href="`/admin/marking/attempts/${attempt.id}`" size="sm">Mark</UiButton>
                        </li>
                    </ul>
                </UiCard>
            </section>
        </div>
    </AppLayout>
</template>
