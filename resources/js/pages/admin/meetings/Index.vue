<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });

const tones = { scheduled: 'brand', held: 'success', cancelled: 'neutral' };
</script>

<template>
    <Head title="Meetings" />

    <AppLayout title="Meetings" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Meetings' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Meetings"
                description="Calls with clients and their minutes. Published minutes never change; the client acknowledges them or says what is wrong, and a correction is the next version."
            >
                <template #actions>
                    <UiButton href="/admin/meetings/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Schedule a meeting
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by title…" empty-title="No meetings yet" empty-description="Schedule the first one, such as a kick-off.">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/meetings/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/meetings/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.starts_at }} · {{ row.client }} · minutes: {{ row.minutes }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
