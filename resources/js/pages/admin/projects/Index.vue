<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, ArrowRight } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';

defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head title="Projects" />

    <AppLayout title="Projects" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Projects' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader title="Projects" description="Everything in delivery, and how far along it is.">
                <template #actions>
                    <UiButton href="/admin/projects/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New project
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="All projects" :value="counts.all ?? 0" />
                <StatTile label="In delivery" :value="counts.active ?? 0" tone="brand" />
                <StatTile
                    label="Past target"
                    :value="counts.overdue ?? 0"
                    :tone="counts.overdue ? 'warning' : 'neutral'"
                    hint="Still open after the date we gave"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search projects by name, reference or phase…"
                empty-title="No projects match"
                empty-description="Try clearing the filters, or start one."
                :only="['table']"
            >
                <template #cell:name="{ row }">
                    <Link :href="`/admin/projects/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>

                <template #cell:progress="{ row }">
                    <div class="min-w-[110px]">
                        <UiProgress :value="row.progress" size="sm" />
                    </div>
                </template>

                <template #cell:target_date="{ row }">
                    <span :style="row.overdue ? { color: 'var(--color-warn-600)' } : {}">{{ row.target_date }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/projects/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/admin/projects/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ row.client }}</span>
                            </span>
                            <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <div class="mt-2"><UiProgress :value="row.progress" size="sm" /></div>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
