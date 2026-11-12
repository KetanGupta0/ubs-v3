<script setup>
import { Head } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    waiting: { type: Number, default: 0 },
    canManage: { type: Boolean, default: false },
});

const states = {
    open: { label: 'Open', tone: 'success' },
    not_yet: { label: 'Not open yet', tone: 'brand' },
    full: { label: 'Full', tone: 'warning' },
    closed: { label: 'Closed', tone: 'neutral' },
};
</script>

<template>
    <Head title="Registration forms" />

    <AppLayout title="Registration forms" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Registration forms' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Registration forms"
                description="Forms for one course that anybody can fill without an account: for a poster, a college visit or a workshop. Each has its own address and QR code."
            >
                <template v-if="canManage" #actions>
                    <UiButton href="/admin/registration-forms/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New form
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Registrations waiting for review" :value="waiting" :tone="waiting ? 'warning' : 'neutral'" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search forms…"
                empty-title="No registration forms yet"
                empty-description="Make one for the next poster or college visit."
                :only="['table', 'waiting']"
            >
                <template #cell:title="{ row }">
                    <a :href="`/admin/registration-forms/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</a>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.path }}</span>
                </template>

                <template #cell:state="{ row }">
                    <UiBadge :tone="states[row.state]?.tone ?? 'neutral'" size="sm" dot>{{ states[row.state]?.label ?? row.state }}</UiBadge>
                </template>

                <template #cell:waiting_count="{ row }">
                    <UiBadge v-if="row.waiting_count" tone="warning" size="sm">{{ row.waiting_count }}</UiBadge>
                    <span v-else style="color: var(--text-muted)">0</span>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/registration-forms/${row.id}`" variant="ghost" size="xs">Open</UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <a :href="`/admin/registration-forms/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="states[row.state]?.tone ?? 'neutral'" size="sm" dot>{{ states[row.state]?.label ?? row.state }}</UiBadge>
                        </div>
                        <p class="mt-1 truncate text-xs" style="color: var(--text-muted)">{{ row.course }}</p>
                        <p class="mt-1 text-xs">{{ row.registrations_count }} registered · {{ row.waiting_count }} to review</p>
                    </a>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
