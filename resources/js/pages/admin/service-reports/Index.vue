<script setup>
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({ table: { type: Object, required: true } });
</script>

<template>
    <Head title="Service reports" />

    <AppLayout title="Service reports" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Service reports' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader title="Service reports" description="One a month for each maintenance contract, drafted on the 1st with the ticket figures. Add uptime, hours and the work done, then publish it into the client's documents." />

            <DataTable :table="table" empty-title="No reports yet" empty-description="The first drafts appear on the 1st of next month.">
                <template #cell:month="{ row }">
                    <Link :href="`/admin/service-reports/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.month }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="row.status === 'published' ? 'success' : 'warning'" size="sm" dot>{{ row.status === 'published' ? 'Published' : 'To finish' }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/service-reports/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.month }} · {{ row.client }}</span>
                            <UiBadge :tone="row.status === 'published' ? 'success' : 'warning'" size="sm" dot>{{ row.status === 'published' ? 'Published' : 'To finish' }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.contract }} · {{ row.tickets }} tickets</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
