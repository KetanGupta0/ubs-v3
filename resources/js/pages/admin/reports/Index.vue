<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { BarChart3, ArrowRight, Mail, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    reports: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
});

function stop(schedule) {
    if (confirm(`Stop sending ${schedule.name}?`)) {
        router.delete(`/admin/reports/schedules/${schedule.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Reports" />

    <AppLayout title="Reports" :breadcrumbs="[{ label: 'Dashboard', href: '/admin' }, { label: 'Reports' }]">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                title="Reports"
                description="The numbers needed to run the business. Every one of them downloads, prints, and can arrive by email on a cadence."
            />

            <UiEmptyState
                v-if="!reports.length"
                :icon="BarChart3"
                title="No reports available to this account"
                description="Reports follow permissions: billing figures need billing access, batch figures need student access."
            />

            <div v-else class="grid gap-4 sm:grid-cols-2">
                <Link v-for="report in reports" :key="report.key" :href="`/admin/reports/${report.key}`">
                    <UiCard interactive class="h-full">
                        <div class="flex h-full flex-col">
                            <h2 class="flex items-center gap-2 text-sm font-semibold">
                                <BarChart3 class="h-4 w-4" style="color: var(--color-brand-500)" />
                                {{ report.title }}
                            </h2>

                            <p class="mt-2 flex-1 text-sm" style="color: var(--text-muted)">
                                {{ report.description }}
                            </p>

                            <p class="mt-4 flex items-center gap-1 text-xs font-medium" style="color: var(--color-brand-600)">
                                Open
                                <ArrowRight class="h-3 w-3" />
                            </p>
                        </div>
                    </UiCard>
                </Link>
            </div>

            <!-- ---------------------------------------------- what is scheduled -->
            <UiCard v-if="schedules.length">
                <template #header>
                    <h2 class="flex items-center gap-2 text-base font-semibold">
                        <Mail class="h-4 w-4" style="color: var(--text-muted)" />
                        Arriving by email
                    </h2>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="schedule in schedules"
                        :key="schedule.id"
                        class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ schedule.name }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ schedule.cadence }} at {{ schedule.hour }} · {{ schedule.recipients.join(', ') }}
                            </span>
                            <span v-if="schedule.lastError" class="block text-xs" style="color: var(--color-danger-500)">
                                Last attempt failed: {{ schedule.lastError }}
                            </span>
                        </span>

                        <UiBadge size="sm">{{ schedule.format }}</UiBadge>
                        <span v-if="schedule.lastSentAt" class="text-xs" style="color: var(--text-muted)">
                            last sent {{ schedule.lastSentAt }}
                        </span>

                        <UiButton variant="ghost" size="xs" aria-label="Stop" @click="stop(schedule)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
