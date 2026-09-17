<script setup>
import { Head } from '@inertiajs/vue3';
import { PlayCircle, CalendarCheck } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    summary: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Attendance" />

    <AppLayout title="Attendance" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Attendance' }]">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader
                title="Attendance"
                description="Which classes you were at, and which you missed. Recordings are linked where there is one."
            />

            <UiEmptyState
                v-if="!summary.length"
                :icon="CalendarCheck"
                title="No classes held yet"
                description="This fills in once your batch starts meeting."
            />

            <UiCard v-for="row in summary" :key="row.courseId">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-base font-semibold">{{ row.course }}</p>
                        <p class="text-xs" style="color: var(--text-muted)">{{ row.batch }}</p>
                    </div>
                    <p class="text-2xl font-semibold tnum"
                       :style="{ color: row.short ? 'var(--color-danger-500)' : 'var(--text-strong)' }">
                        {{ row.percent !== null ? `${row.percent}%` : '—' }}
                    </p>
                </div>

                <div class="mt-3">
                    <UiProgress
                        :value="row.percent ?? 0"
                        :label="`${row.attended} of ${row.held} classes attended`"
                        :tone="row.short ? 'danger' : 'success'"
                        :show-value="false"
                    />
                </div>

                <p v-if="row.short" class="mt-2 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                   style="background: var(--surface-sunken); color: var(--color-danger-500)">
                    This course needs {{ row.minimum }}%. Below it, the certificate is held back. If something is
                    getting in the way, tell your trainer rather than letting it slide.
                </p>

                <div v-if="row.missed.length" class="mt-4">
                    <p class="mb-2 text-xs font-medium" style="color: var(--text-muted)">Classes you missed</p>
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="session in row.missed" :key="session.id"
                            class="flex items-center gap-3 py-2 first:pt-0 last:pb-0">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm">{{ session.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ session.on }}</span>
                            </span>
                            <UiButton
                                v-if="session.recordingUrl"
                                :href="session.recordingUrl"
                                :inertia="false"
                                target="_blank"
                                variant="ghost"
                                size="xs"
                            >
                                <template #leading><PlayCircle class="h-3 w-3" /></template>
                                Catch up
                            </UiButton>
                        </li>
                    </ul>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
