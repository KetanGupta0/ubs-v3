<script setup>
import { Head } from '@inertiajs/vue3';
import { Video, Clock, PlayCircle, CalendarX, CheckCircle2, XCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    upcoming: { type: Array, default: () => [] },
    past: { type: Array, default: () => [] },
    attendance: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Live classes" />

    <AppLayout title="Live classes" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Classes' }]">
        <div class="mx-auto max-w-4xl space-y-6">
            <PageHeader
                title="Live classes"
                description="Every class runs on Google Meet. The join button opens fifteen minutes before the start."
            />

            <!-- ------------------------------------------------- upcoming -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Coming up
                </h2>

                <UiEmptyState
                    v-if="!upcoming.length"
                    :icon="CalendarX"
                    title="Nothing scheduled"
                    description="New classes appear here as soon as your trainer puts them in."
                />

                <ul v-else class="space-y-3">
                    <li v-for="session in upcoming" :key="session.id">
                        <UiCard :padding="'p-4'">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                        {{ session.title }}
                                        <UiBadge v-if="session.live" tone="danger" size="sm" dot>live now</UiBadge>
                                        <UiBadge v-if="session.cancelled" size="sm">cancelled</UiBadge>
                                    </p>
                                    <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                        {{ [session.course, session.batch].filter(Boolean).join(' · ') }}
                                    </p>
                                    <p class="mt-1 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                        <Clock class="h-3 w-3" />
                                        {{ session.at }} · {{ session.duration }} min · {{ session.startsIn }}
                                    </p>
                                    <p v-if="session.agenda" class="mt-1.5 text-xs" style="color: var(--text-muted)">
                                        {{ session.agenda }}
                                    </p>
                                </div>

                                <UiButton
                                    v-if="session.joinable"
                                    :href="`/student/classes/${session.id}/join`"
                                    :inertia="false"
                                    target="_blank"
                                    :variant="session.live ? 'primary' : 'secondary'"
                                    size="sm"
                                >
                                    <template #leading><Video class="h-3.5 w-3.5" /></template>
                                    Join
                                </UiButton>
                                <span v-else-if="!session.cancelled" class="text-xs" style="color: var(--text-muted)">
                                    Opens 15 min before
                                </span>
                            </div>
                        </UiCard>
                    </li>
                </ul>
            </section>

            <!-- ----------------------------------------------- attendance -->
            <section v-if="attendance.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Your attendance
                </h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <UiCard v-for="row in attendance" :key="row.courseId" :padding="'p-4'">
                        <p class="text-sm font-semibold">{{ row.course }}</p>
                        <p class="text-xs" style="color: var(--text-muted)">{{ row.batch }}</p>

                        <div class="mt-3">
                            <UiProgress
                                :value="row.percent ?? 0"
                                :label="`${row.attended} of ${row.held} classes`"
                                :tone="row.short ? 'danger' : 'success'"
                            />
                        </div>

                        <p v-if="row.short" class="mt-2 text-xs" style="color: var(--color-danger-500)">
                            Below the {{ row.minimum }}% this course needs. Talk to your trainer.
                        </p>
                    </UiCard>
                </div>
            </section>

            <!-- ---------------------------------------------------- past -->
            <section v-if="past.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Classes held
                </h2>

                <ul class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]"
                    style="border-color: var(--border-subtle)">
                    <li v-for="session in past" :key="session.id" class="flex flex-wrap items-center gap-3 p-3.5">
                        <component
                            :is="session.attended ? CheckCircle2 : XCircle"
                            class="h-4 w-4 shrink-0"
                            :style="{ color: session.attended ? 'var(--color-signal-500)' : 'var(--color-danger-500)' }"
                        />

                        <span class="min-w-0 flex-1">
                            <span class="block text-sm">{{ session.title }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ session.course }} · {{ session.at }}
                            </span>
                        </span>

                        <UiBadge :tone="session.attended ? 'success' : 'neutral'" size="sm">{{ session.status }}</UiBadge>

                        <UiButton
                            v-if="session.recordingUrl"
                            :href="session.recordingUrl"
                            :inertia="false"
                            target="_blank"
                            variant="ghost"
                            size="xs"
                        >
                            <template #leading><PlayCircle class="h-3 w-3" /></template>
                            Recording
                        </UiButton>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
