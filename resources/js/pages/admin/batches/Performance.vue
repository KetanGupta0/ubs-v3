<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    table: { type: Object, required: true },
    slipping: { type: Number, default: 0 },
});

const base = `/admin/batches/${props.batch.routeKey}`;
</script>

<template>
    <Head :title="`Performance: ${batch.name}`" />

    <AppLayout
        :title="`Performance: ${batch.name}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Batches', href: '/admin/batches' }, { label: batch.name, href: `${base}/run` }, { label: 'Performance' }]"
    >
        <div class="mx-auto max-w-7xl">
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

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Showing a signal" :value="slipping" :tone="slipping ? 'warning' : 'neutral'" :href="`${base}/performance?filter[signal]=any`" />
                <StatTile label="Classes held" :value="batch.held" />
                <StatTile label="Work past its date" :value="batch.due" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search students…"
                empty-title="Nobody matches"
                empty-description="Clear the signal filter to see the whole batch."
                :only="['table', 'slipping']"
            >
                <template #cell:student="{ row }">
                    <Link :href="`/admin/students/${row.userId}`" class="block font-medium hover:underline" style="color: var(--text-strong)">{{ row.student }}</Link>
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
