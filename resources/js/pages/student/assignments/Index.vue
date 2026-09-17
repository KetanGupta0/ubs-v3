<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { FileText, ArrowRight, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    assignments: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Assignments" />

    <AppLayout title="Assignments" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Assignments' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                title="Assignments and projects"
                description="What has been set, what you have handed in, and what came back."
            />

            <UiEmptyState
                v-if="!assignments.length"
                :icon="FileText"
                title="Nothing set yet"
                description="Assignments appear here as your trainer publishes them."
            />

            <ul v-else class="space-y-2">
                <li v-for="assignment in assignments" :key="assignment.id">
                    <Link
                        :href="`/student/assignments/${assignment.id}`"
                        class="flex flex-wrap items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition
                               hover:shadow-[var(--shadow-card)]"
                        :style="{
                            borderColor: assignment.overdue && !assignment.submitted
                                ? 'var(--color-warn-500)'
                                : 'var(--border-subtle)',
                        }"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold">{{ assignment.title }}</span>
                                <UiBadge v-if="assignment.isProject" tone="accent" size="sm">project</UiBadge>
                            </span>
                            <span class="mt-0.5 block text-xs" style="color: var(--text-muted)">
                                {{ assignment.course }}
                                <span v-if="assignment.dueAt"> · due {{ assignment.dueAt }}</span>
                                <span v-if="assignment.submittedAt"> · handed in {{ assignment.submittedAt }}</span>
                            </span>
                        </span>

                        <span class="flex shrink-0 items-center gap-2">
                            <UiBadge v-if="assignment.marks !== null" tone="success" size="sm">
                                {{ assignment.marks }} / {{ assignment.maxMarks }}
                            </UiBadge>
                            <UiBadge v-else-if="assignment.status === 'returned'" tone="warning" size="sm">
                                sent back
                            </UiBadge>
                            <UiBadge v-else-if="assignment.submitted" size="sm">
                                <CheckCircle2 class="mr-1 h-3 w-3" />waiting to be marked
                            </UiBadge>
                            <UiBadge v-else-if="assignment.overdue" tone="warning" size="sm">
                                <AlertTriangle class="mr-1 h-3 w-3" />overdue
                            </UiBadge>
                            <UiBadge v-else size="sm">not started</UiBadge>

                            <ArrowRight class="h-4 w-4" style="color: var(--text-muted)" />
                        </span>

                        <UiBadge v-if="assignment.late" tone="warning" size="sm">late</UiBadge>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
