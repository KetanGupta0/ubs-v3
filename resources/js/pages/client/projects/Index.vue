<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

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
    <Head title="Your projects" />

    <AppLayout title="Projects" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Projects' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Projects"
                description="Everything we are building for you, and how far along each one is."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-2">
                <StatTile label="All projects" :value="counts.all ?? 0" />
                <StatTile label="In progress" :value="counts.active ?? 0" tone="brand" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search your projects…"
                empty-title="No projects yet"
                empty-description="Anything we start for you will appear here."
                :only="['table']"
            >
                <template #cell:name="{ row }">
                    <Link :href="`/client/projects/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>

                <template #cell:progress="{ row }">
                    <div class="min-w-[120px]">
                        <UiProgress :value="row.progress" size="sm" :show-value="true" />
                    </div>
                </template>

                <template #cell:target_date="{ row }">
                    <span :style="row.overdue ? { color: 'var(--color-warn-600)' } : {}">{{ row.target_date }}</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/client/projects/${row.id}`" variant="ghost" size="xs">
                        Open
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <Link :href="`/client/projects/${row.id}`" class="block">
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ row.code }}</span>
                            </span>
                            <UiBadge size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <div class="mt-2.5">
                            <UiProgress :value="row.progress" size="sm" :label="row.phase" />
                        </div>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
