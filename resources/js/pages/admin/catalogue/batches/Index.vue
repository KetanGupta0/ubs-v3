<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Pencil } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
});

const statusTones = { upcoming: 'brand', running: 'success', completed: 'neutral', cancelled: 'danger' };
</script>

<template>
    <Head title="Batches" />

    <AppLayout title="Batches" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Batches' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Batches"
                description="Seat counts are shown on the public site, so they are treated as numbers that must be true."
            >
                <template #actions>
                    <UiButton href="/admin/batches/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New batch
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-2">
                <StatTile label="Upcoming" :value="counts.upcoming ?? 0" tone="brand" />
                <StatTile label="Running now" :value="counts.running ?? 0" tone="success" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search batches…"
                empty-title="No batches match"
                empty-description="Create one to open enrolment."
                :only="['table']"
            >
                <template #cell:name="{ row }">
                    <Link :href="`/admin/batches/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        <UiBadge v-if="!row.published" size="sm">unpublished</UiBadge>
                    </span>
                </template>

                <template #cell:seats="{ row }">
                    <span :style="row.nearlyFull ? { color: 'var(--color-warn-600)' } : {}" class="tnum">{{ row.seats }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/batches/${row.id}/edit`" variant="ghost" size="xs" icon aria-label="Edit">
                        <Pencil class="h-3.5 w-3.5" />
                    </UiButton>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
