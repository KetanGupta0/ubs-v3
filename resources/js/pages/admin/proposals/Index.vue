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

const tones = { accepted: 'success', rejected: 'danger', sent: 'brand', draft: 'neutral', withdrawn: 'neutral' };
</script>

<template>
    <Head title="Proposals" />

    <AppLayout title="Proposals" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Proposals' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader title="Proposals" description="What we have quoted, and what came back.">
                <template #actions>
                    <UiButton href="/admin/proposals/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New proposal
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="All proposals" :value="counts.all ?? 0" />
                <StatTile
                    label="Awaiting an answer"
                    :value="counts.awaiting ?? 0"
                    :tone="counts.awaiting ? 'brand' : 'neutral'"
                />
                <StatTile label="Accepted" :value="counts.accepted ?? 0" tone="success" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by number or title…"
                empty-title="No proposals match"
                empty-description="Try clearing the filters, or write one."
                :only="['table']"
            >
                <template #cell:title="{ row }">
                    <Link :href="`/admin/proposals/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.title }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>

                <template #cell:valid_until="{ row }">
                    <span :style="row.expired ? { color: 'var(--color-warn-600)' } : {}">{{ row.valid_until }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/proposals/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/proposals/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ row.client }} · {{ row.number }}
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
