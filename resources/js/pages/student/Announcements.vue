<script setup>
import { Head } from '@inertiajs/vue3';
import { Megaphone, Pin } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    announcements: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Announcements" />

    <AppLayout title="Announcements" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Announcements' }]">
        <div class="mx-auto max-w-3xl space-y-4">
            <PageHeader
                title="Announcements"
                description="Notices from your trainers, newest first. Pinned ones stay at the top."
            />

            <UiEmptyState
                v-if="!announcements.length"
                :icon="Megaphone"
                title="Nothing announced"
                description="Class changes, deadlines and notices from your trainer land here."
            />

            <UiCard
                v-for="announcement in announcements"
                :key="announcement.id"
                :style="announcement.pinned ? { borderColor: 'var(--color-brand-300)' } : undefined"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <h2 class="flex items-center gap-2 text-sm font-semibold">
                        <Pin v-if="announcement.pinned" class="h-3.5 w-3.5" style="color: var(--color-brand-500)" />
                        {{ announcement.title }}
                    </h2>
                    <UiBadge size="sm">{{ announcement.scope }}</UiBadge>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">{{ announcement.body }}</p>

                <p class="mt-3 text-xs" style="color: var(--text-muted)">
                    {{ announcement.author }} · {{ announcement.at }} · {{ announcement.ago }}
                </p>
            </UiCard>
        </div>
    </AppLayout>
</template>
