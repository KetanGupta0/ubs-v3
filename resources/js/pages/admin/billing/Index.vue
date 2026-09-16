<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, ArrowRight, FileText } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
});

const tones = { paid: 'success', pending: 'brand', cancelled: 'neutral', refunded: 'warning' };
</script>

<template>
    <Head title="Billing" />

    <AppLayout title="Billing" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Billing' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Payment requests"
                description="Money asked for, across both business lines. Raising one issues its invoice at the same moment."
            >
                <template #actions>
                    <UiButton href="/admin/invoices" variant="secondary" size="sm">
                        <template #leading><FileText class="h-3.5 w-3.5" /></template>
                        Invoices
                    </UiButton>
                    <UiButton href="/admin/billing/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Raise a request
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Outstanding" :value="totals.pending ?? '₹0.00'" tone="brand" />
                <StatTile
                    label="Overdue"
                    :value="totals.overdue ?? '₹0.00'"
                    :tone="totals.overdueCount ? 'danger' : 'neutral'"
                    :hint="`${totals.overdueCount ?? 0} past the due date`"
                />
                <StatTile label="Collected this month" :value="totals.collectedThisMonth ?? '₹0.00'" tone="success" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by reference or what it is for…"
                empty-title="No requests match"
                empty-description="Try clearing the filters, or raise one."
                :only="['table']"
            >
                <template #cell:title="{ row }">
                    <Link :href="`/admin/billing/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.title }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>

                <template #cell:due_on="{ row }">
                    <span :style="row.overdue ? { color: 'var(--color-danger-500)' } : {}">{{ row.due_on }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/billing/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/billing/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.person }} · {{ row.reference }}
                                </span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-semibold tnum">{{ row.total }}</span>
                                <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm">{{ row.status }}</UiBadge>
                            </span>
                        </div>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
