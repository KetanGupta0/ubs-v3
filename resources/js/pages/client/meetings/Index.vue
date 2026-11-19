<script setup>
/** The client's meetings with us (CL11): what is coming up, then what happened. */
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    upcoming: { type: Array, default: () => [] },
    past: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Meetings" />

    <AppLayout title="Meetings" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Meetings' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader title="Meetings" description="Calls with us, and their minutes. Times are India time." />

            <UiCard v-if="!upcoming.length && !past.length">
                <UiEmptyState :icon="CalendarClock" title="No meetings yet" description="When we set one up, it appears here and in your email." />
            </UiCard>

            <section v-if="upcoming.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Coming up</h2>
                <ul class="space-y-3">
                    <li v-for="meeting in upcoming" :key="meeting.id">
                        <Link :href="`/client/meetings/${meeting.id}`" class="flex items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition hover:shadow-[var(--shadow-card)]" style="border-color: var(--border-subtle)">
                            <CalendarClock class="h-5 w-5 shrink-0" style="color: var(--text-brand)" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ meeting.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ meeting.when }}<template v-if="meeting.project"> · {{ meeting.project.name }}</template></span>
                            </span>
                            <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" aria-hidden="true" />
                        </Link>
                    </li>
                </ul>
            </section>

            <section v-if="past.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Earlier</h2>
                <UiCard>
                    <ul class="divide-y">
                        <li v-for="meeting in past" :key="meeting.id">
                            <Link :href="`/client/meetings/${meeting.id}`" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ meeting.title }}</span>
                                    <span class="block text-xs" style="color: var(--text-muted)">{{ meeting.when }}</span>
                                </span>
                                <UiBadge v-if="meeting.minutesWaiting" tone="warning" size="sm" dot>Minutes to read</UiBadge>
                                <UiBadge v-else-if="meeting.status === 'cancelled'" size="sm">Cancelled</UiBadge>
                                <UiBadge v-else-if="meeting.status === 'held'" tone="success" size="sm" dot>Minutes</UiBadge>
                            </Link>
                        </li>
                    </ul>
                </UiCard>
            </section>
        </div>
    </AppLayout>
</template>
