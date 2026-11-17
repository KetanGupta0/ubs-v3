<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });

const tones = { requested: 'warning', quoted: 'brand', approved: 'success', declined: 'danger', withdrawn: 'neutral' };
</script>

<template>
    <Head title="Change requests" />

    <AppLayout title="Change requests" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Change requests' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Change requests"
                description="Work outside the agreed scope. Estimate it and send it as an addendum; the client approves by signing, and the project's value, dates and milestones follow."
            >
                <template #actions>
                    <UiButton href="/admin/change-requests/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Record a change
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by number or title…" empty-title="No change requests yet" empty-description="Clients ask from their project page, or record one for them.">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/change-requests/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/change-requests/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.number }} · {{ row.project }} · {{ row.amount }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
