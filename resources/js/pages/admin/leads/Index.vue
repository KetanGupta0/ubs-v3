<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Inbox, ArrowRight } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    statuses: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    counts: { type: Object, default: () => ({}) },
});

const statusTones = {
    new: 'brand',
    contacted: 'accent',
    qualified: 'warning',
    converted: 'success',
    lost: 'neutral',
};
</script>

<template>
    <Head title="Enquiries" />

    <AppLayout title="Enquiries" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Enquiries' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Enquiries"
                description="Everything that came through a form on the site, with the page it came from."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="All enquiries" :value="counts.all ?? 0" />
                <StatTile label="Still open" :value="counts.open ?? 0" tone="brand" />
                <StatTile
                    label="Nobody assigned"
                    :value="counts.unassigned ?? 0"
                    :tone="counts.unassigned ? 'warning' : 'neutral'"
                    hint="An enquiry belonging to everyone belongs to no one"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by name, email, reference or message…"
                empty-title="No enquiries match"
                empty-description="Try clearing the filters. New enquiries appear here the moment a form is submitted."
                :only="['table']"
            >
                <template #cell:reference="{ row }">
                    <Link :href="`/admin/leads/${row.id}`" class="font-mono text-xs hover:underline">
                        {{ row.reference }}
                    </Link>
                </template>

                <template #cell:name="{ row }">
                    <Link :href="`/admin/leads/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <span class="inline-flex items-center gap-1.5">
                        <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        <UiBadge v-if="row.isStale" tone="danger" size="sm">overdue</UiBadge>
                    </span>
                </template>

                <template #cell:assignee="{ value }">
                    <span :style="value ? {} : { color: 'var(--text-muted)' }">{{ value ?? 'Unassigned' }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/leads/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/leads/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block truncate text-xs" style="color: var(--text-muted)">{{ row.subject }}</span>
                            </span>
                            <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs" style="color: var(--text-muted)">
                            <span class="font-mono">{{ row.reference }}</span>
                            <span>{{ row.created_at }}</span>
                        </div>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
