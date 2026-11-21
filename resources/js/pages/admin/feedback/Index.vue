<script setup>
import { Head, Link } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({ table: { type: Object, required: true } });

const tones = { new: 'warning', acknowledged: 'brand', in_progress: 'brand', fixed: 'success', closed: 'neutral', declined: 'neutral' };
</script>

<template>
    <Head title="Staging feedback" />

    <AppLayout title="Staging feedback" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Staging feedback' }]">
        <div>
            <PageHeader title="Staging feedback" description="What clients found on the staging previews. Move each along; when it is fixed, the person who reported it is asked to check." />

            <DataTable :table="table" search-placeholder="Search feedback…" empty-title="Nothing reported" empty-description="Clients report from their project's Feedback page.">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/feedback/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/feedback/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.project }} · {{ row.client }} · {{ row.kind }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
