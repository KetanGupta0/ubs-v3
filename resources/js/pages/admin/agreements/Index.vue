<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });

const tones = { draft: 'neutral', sent: 'warning', signed: 'brand', countersigned: 'success', superseded: 'neutral', withdrawn: 'danger' };
</script>

<template>
    <Head title="Agreements" />

    <AppLayout title="Agreements" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Agreements' }]">
        <div>
            <PageHeader
                title="Agreements"
                description="Service agreements, statements of work, NDAs and licences. A draft can change; once sent, its text and PDF are fixed, and a change is a new version."
            >
                <template #actions>
                    <UiButton href="/admin/agreements/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New agreement
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by number or title…" empty-title="No agreements yet" empty-description="Write the first one with New agreement.">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/agreements/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/agreements/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.title }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.number }} · {{ row.client }} · {{ row.kind }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
