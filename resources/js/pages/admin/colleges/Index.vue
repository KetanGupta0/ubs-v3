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
    <Head title="Colleges" />

    <AppLayout title="Colleges" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Colleges' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Colleges"
                description="Institutions we run internships with. The memorandum, the coordinator and the batches belong to the college, not to any one student."
            >
                <template #actions>
                    <UiButton href="/admin/colleges/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Add a college
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="All colleges" :value="counts.all ?? 0" />
                <StatTile label="Active" :value="counts.active ?? 0" tone="success" />
                <StatTile
                    label="Memorandum expiring"
                    :value="counts.expiring ?? 0"
                    :tone="counts.expiring ? 'warning' : 'neutral'"
                    hint="Within the next 60 days"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search by college, city, university or coordinator…"
                empty-title="No colleges match"
                empty-description="Try clearing the filters, or add one."
                :only="['table']"
            >
                <template #cell:name="{ row }">
                    <Link :href="`/admin/colleges/${row.slug}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:mou="{ row }">
                    <UiBadge v-if="row.mouExpired" tone="danger" size="sm">{{ row.mou }}</UiBadge>
                    <UiBadge v-else-if="row.mouExpiring" tone="warning" size="sm">{{ row.mou }}</UiBadge>
                    <span v-else>{{ row.mou }}</span>
                </template>

                <template #cell:state="{ row }">
                    <UiBadge :tone="row.active ? 'success' : 'neutral'" size="sm" dot>{{ row.state }}</UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/colleges/${row.slug}/desk`" variant="secondary" size="xs">
                        Students
                    </UiButton>
                    <UiButton :href="`/admin/colleges/${row.slug}/edit`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/colleges/${row.slug}/edit`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ row.location }}</span>
                            </span>
                            <UiBadge :tone="row.active ? 'success' : 'neutral'" size="sm" dot>{{ row.state }}</UiBadge>
                        </div>
                        <p class="mt-2 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                            <span>{{ row.students }} student{{ row.students === 1 ? '' : 's' }}</span>
                            <UiBadge v-if="row.mouExpired" tone="danger" size="sm">{{ row.mou }}</UiBadge>
                            <UiBadge v-else-if="row.mouExpiring" tone="warning" size="sm">{{ row.mou }}</UiBadge>
                            <span v-else>{{ row.mou }}</span>
                        </p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
