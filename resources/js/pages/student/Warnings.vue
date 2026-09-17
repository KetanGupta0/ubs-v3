<script setup>
/**
 * What has been said to this student about how they are doing.
 *
 * The trainer's private note is deliberately not here. A note written for the
 * people running the course stops being that the moment the student can read
 * it, and the honest version of the message is the one in `reason`.
 */
import { Head, router } from '@inertiajs/vue3';
import { ShieldAlert, Check, Info } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    warnings: { type: Array, default: () => [] },
    unacknowledged: { type: Number, default: 0 },
});

const tones = { notice: 'brand', warning: 'warning', escalation: 'danger' };

const acknowledge = (id) => {
    router.post(`/student/warnings/${id}/acknowledge`, {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Notices" />

    <AppLayout title="Notices" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Notices' }]">
        <div class="mx-auto max-w-3xl space-y-4">
            <PageHeader
                title="Notices about your progress"
                description="Sent when attendance or work slips, while there is still time to do something about it."
            >
                <template #actions>
                    <UiBadge v-if="unacknowledged" tone="warning">{{ unacknowledged }} unread</UiBadge>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!warnings.length"
                :icon="Check"
                title="Nothing here, which is the good outcome"
                description="Notices only appear if you fall behind on classes or work."
            />

            <UiCard
                v-for="warning in warnings"
                :key="warning.id"
                :style="!warning.acknowledged && !warning.resolved
                    ? { borderColor: 'var(--color-warn-500)' }
                    : undefined"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <ShieldAlert class="h-4 w-4" style="color: var(--text-muted)" />
                        {{ warning.levelLabel }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <UiBadge :tone="tones[warning.level] ?? 'neutral'" size="sm">{{ warning.level }}</UiBadge>
                        <UiBadge v-if="warning.resolved" tone="success" size="sm">resolved</UiBadge>
                    </div>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">{{ warning.reason }}</p>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs" style="color: var(--text-muted)">
                        {{ [warning.batch, warning.at].filter(Boolean).join(' · ') }}
                    </p>

                    <UiButton
                        v-if="!warning.acknowledged && !warning.resolved"
                        size="sm"
                        variant="secondary"
                        @click="acknowledge(warning.id)"
                    >
                        <template #leading><Check class="h-3.5 w-3.5" /></template>
                        I have read this
                    </UiButton>
                    <span v-else-if="warning.acknowledged" class="text-xs" style="color: var(--text-muted)">
                        Read
                    </span>
                </div>
            </UiCard>

            <p
                v-if="warnings.length"
                class="flex items-start gap-2 px-1 text-xs"
                style="color: var(--text-muted)"
            >
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span>
                    These are between you and your trainer. If something outside the course is the reason, say so —
                    the point of sending one early is that it can still be fixed.
                </span>
            </p>
        </div>
    </AppLayout>
</template>
