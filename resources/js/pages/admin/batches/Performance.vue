<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import ActivityHeatmapCard from '@/components/Student/ActivityHeatmapCard.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    table: { type: Object, required: true },
    slipping: { type: Number, default: 0 },
    heatmap: { type: Array, default: () => [] },
    online: { type: Array, default: () => [] },
});

const base = `/admin/batches/${props.batch.routeKey}`;
</script>

<template>
    <Head :title="`Performance: ${batch.name}`" />

    <AppLayout
        :title="`Performance: ${batch.name}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Batches', href: '/admin/batches' }, { label: batch.name, href: `${base}/run` }, { label: 'Performance' }]"
    >
        <div>
            <PageHeader
                :title="`How ${batch.name} is doing`"
                :description="`${batch.course}. Counted from classes marked, work handed in, quizzes sat and lessons finished. A signal is a prompt to look, not a verdict; warnings are still yours to give.`"
            >
                <template #actions>
                    <UiButton :href="`${base}/run`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        The batch
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Showing a signal" :value="slipping" :tone="slipping ? 'warning' : 'neutral'" :href="`${base}/performance?filter[signal]=any`" />
                <StatTile label="Online now" :value="online.length" :tone="online.length ? 'success' : 'neutral'" hint="Seen in the last five minutes" />
                <StatTile label="Classes held" :value="batch.held" />
                <StatTile label="Work past its date" :value="batch.due" />
            </div>

            <div v-if="online.length" class="mb-5 flex flex-wrap items-center gap-2 text-sm">
                <span style="color: var(--text-muted)">Online now:</span>
                <span v-for="person in online" :key="person.id" class="inline-flex items-center gap-1.5 rounded-full border py-0.5 pl-0.5 pr-2.5" style="border-color: var(--border-subtle)">
                    <UiAvatar :name="person.name" :src="person.avatarUrl" size="xs" />
                    {{ person.name }}
                </span>
            </div>

            <div class="mb-5">
                <ActivityHeatmapCard
                    :days="heatmap"
                    title="Students active per day, last twelve weeks"
                    subtitle="How many of the batch did anything that day: signed in, studied, joined a class, handed in work, sat a quiz or posted in the group."
                    :noun="['student', 'students']"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search students…"
                empty-title="Nobody matches"
                empty-description="Clear the signal filter to see the whole batch."
                :only="['table', 'slipping']"
            >
                <template #cell:student="{ row }">
                    <Link :href="`/admin/students/${row.userId}`" class="flex items-center gap-1.5 font-medium hover:underline" style="color: var(--text-strong)">
                        <span v-if="row.online" class="inline-block h-2 w-2 shrink-0 rounded-full" style="background: var(--color-signal-500)" title="Online now" aria-label="Online now" />
                        {{ row.student }}
                    </Link>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.email }}</span>
                </template>
                <template #cell:signals="{ row }">
                    <span v-if="!row.signalKeys.length" style="color: var(--text-muted)">—</span>
                    <span v-else class="flex flex-wrap gap-1">
                        <UiBadge v-for="label in row.signals.split(', ')" :key="label" tone="warning" size="sm">{{ label }}</UiBadge>
                    </span>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/students/${row.userId}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.student }}</span>
                            <UiBadge v-if="row.signalKeys.length" tone="warning" size="sm">{{ row.signalKeys.length }} signal{{ row.signalKeys.length === 1 ? '' : 's' }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">
                            Attendance {{ row.attended }} · quizzes {{ row.quiz_average }} · lessons {{ row.progress_percent }}
                        </p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
