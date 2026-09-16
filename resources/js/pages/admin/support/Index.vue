<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, AlertTriangle, ShieldCheck } from 'lucide-vue-next';

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

const priorityTones = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' };
</script>

<template>
    <Head title="Tickets" />

    <AppLayout title="Tickets" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Tickets' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Support tickets"
                description="Everything clients have raised against their maintenance cover."
            >
                <template #actions>
                    <UiButton href="/admin/contracts" variant="secondary" size="sm">
                        <template #leading><ShieldCheck class="h-3.5 w-3.5" /></template>
                        Contracts
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Open" :value="counts.open ?? 0" tone="brand" />
                <StatTile
                    label="Unassigned"
                    :value="counts.unassigned ?? 0"
                    :tone="counts.unassigned ? 'warning' : 'neutral'"
                />
                <StatTile
                    label="Past the promise"
                    :value="counts.breaching ?? 0"
                    :tone="counts.breaching ? 'danger' : 'success'"
                    hint="Response or resolution overdue"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search tickets by reference, subject or text…"
                empty-title="No tickets match"
                empty-description="Try clearing the filters."
                :only="['table']"
            >
                <template #cell:subject="{ row }">
                    <Link :href="`/admin/tickets/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.subject }}
                    </Link>
                </template>

                <template #cell:priority="{ row }">
                    <UiBadge :tone="priorityTones[row.priority]" size="sm">{{ row.priority }}</UiBadge>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>

                <template #cell:sla="{ row }">
                    <span :style="row.breached ? { color: 'var(--color-danger-500)' } : {}">
                        <AlertTriangle v-if="row.breached" class="mr-1 inline h-3 w-3" />{{ row.sla }}
                    </span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/tickets/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/tickets/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.subject }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.client }} · {{ row.reference }}
                                </span>
                            </span>
                            <UiBadge :tone="priorityTones[row.priority]" size="sm">{{ row.priority }}</UiBadge>
                        </div>
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                            <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                            <span :style="row.breached ? { color: 'var(--color-danger-500)' } : {}">{{ row.sla }}</span>
                        </p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
