<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, ExternalLink, Eye } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });
</script>

<template>
    <Head title="Pages" />

    <AppLayout title="Pages" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Pages' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Pages"
                description="Pages on the public site that you write here: an event, a policy, a campaign. Each lives at /pages/its-address once published."
            >
                <template #actions>
                    <UiButton href="/admin/pages/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New page
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search pages…" empty-title="No pages yet" empty-description="Write one with New page.">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/pages/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:path="{ row }">
                    <code class="text-xs" style="color: var(--text-muted)">{{ row.path }}</code>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="row.status === 'published' ? 'success' : 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>
                <template #rowActions="{ row }">
                    <span class="flex justify-end gap-1">
                        <UiButton :href="`/admin/pages/${row.id}/preview`" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><Eye class="h-3 w-3" /></template>
                            Preview
                        </UiButton>
                        <UiButton v-if="row.status === 'published'" :href="row.path" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><ExternalLink class="h-3 w-3" /></template>
                            Live
                        </UiButton>
                    </span>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
