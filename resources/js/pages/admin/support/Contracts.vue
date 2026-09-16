<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, ArrowRight } from 'lucide-vue-next';

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
</script>

<template>
    <Head title="Maintenance contracts" />

    <AppLayout
        title="Contracts"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Contracts' }]"
    >
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Maintenance contracts"
                description="What each client is covered for, and how fast we promised to answer."
            >
                <template #actions>
                    <UiButton href="/admin/contracts/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New contract
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-2">
                <StatTile label="Active" :value="counts.active ?? 0" tone="success" />
                <StatTile
                    label="Expiring within 45 days"
                    :value="counts.expiring ?? 0"
                    :tone="counts.expiring ? 'warning' : 'neutral'"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by reference or plan…"
                empty-title="No contracts match"
                empty-description="Try clearing the filters, or create one."
                :only="['table']"
            >
                <template #cell:reference="{ row }">
                    <Link :href="`/admin/contracts/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.reference }}
                    </Link>
                </template>

                <template #cell:ends_on="{ row }">
                    <span :style="row.expired ? { color: 'var(--color-danger-500)' } : row.expiring ? { color: 'var(--color-warn-600)' } : {}">
                        {{ row.ends_on }}
                    </span>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="row.expired ? 'danger' : row.status === 'active' ? 'success' : 'neutral'" size="sm" dot>
                        {{ row.expired ? 'expired' : row.status }}
                    </UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/contracts/${row.id}/edit`" variant="ghost" size="xs">
                        Edit
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/contracts/${row.id}/edit`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.client }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.plan }} · {{ row.reference }}
                                </span>
                            </span>
                            <UiBadge :tone="row.expired ? 'danger' : 'success'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-2 text-xs" style="color: var(--text-muted)">
                            Ends {{ row.ends_on }} · {{ row.tickets }} ticket{{ row.tickets === 1 ? '' : 's' }}
                        </p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
