<script setup>
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    waiting: { type: Number, default: 0 },
});

const tones = { open: 'warning', answered: 'success', hidden: 'neutral' };
</script>

<template>
    <Head title="Questions" />

    <AppLayout title="Questions" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Questions' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Questions on lessons"
                description="What students asked while studying. An answer here goes to the student who asked and shows to their whole batch under the lesson."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Waiting for an answer" :value="waiting" :tone="waiting ? 'warning' : 'neutral'" href="/admin/doubts?filter[status]=open" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search questions…"
                empty-title="No questions here"
                empty-description="Nothing waiting. Clear the filters to see answered ones."
                :only="['table', 'waiting']"
            >
                <template #cell:question="{ row }">
                    <Link :href="`/admin/doubts/${row.id}`" class="block max-w-md hover:underline" style="color: var(--text-strong)">{{ row.question }}</Link>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.course }}</span>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/doubts/${row.id}`" variant="ghost" size="xs">{{ row.status === 'open' ? 'Answer' : 'Open' }}</UiButton>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/doubts/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.lesson }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 line-clamp-2 text-sm">{{ row.question }}</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.asker }} · {{ row.batch }} · {{ row.last_activity_at }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
