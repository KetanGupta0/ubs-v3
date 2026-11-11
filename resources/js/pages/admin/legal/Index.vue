<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, PenLine } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });
</script>

<template>
    <Head title="Legal documents" />

    <AppLayout title="Legal documents" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Legal documents' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Legal documents"
                description="The privacy policy, terms and refund policy on the public site. A change is a new version with a note on what changed; every published version stays readable, because people agreed to it."
            />

            <DataTable :table="table" search-placeholder="Search documents…" empty-title="No documents">
                <template #cell:title="{ row }">
                    <Link :href="`/admin/legal/${row.key}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.title }}</Link>
                </template>
                <template #cell:path="{ row }">
                    <code class="text-xs" style="color: var(--text-muted)">{{ row.path }}</code>
                </template>
                <template #cell:in_force="{ row }">
                    <span v-if="row.published">{{ row.in_force }}</span>
                    <UiBadge v-else tone="warning" size="sm" dot>not published</UiBadge>
                </template>
                <template #cell:draft="{ row }">
                    <UiBadge v-if="row.draftVersion" tone="neutral" size="sm" dot>{{ row.draft }} draft</UiBadge>
                    <span v-else style="color: var(--text-muted)">—</span>
                </template>
                <template #rowActions="{ row }">
                    <span class="flex justify-end gap-1">
                        <UiButton :href="`/admin/legal/${row.key}`" variant="ghost" size="xs">
                            <template #leading><PenLine class="h-3 w-3" /></template>
                            {{ row.draftVersion ? 'Continue draft' : 'Edit' }}
                        </UiButton>
                        <UiButton v-if="row.published" :href="row.path" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><ExternalLink class="h-3 w-3" /></template>
                            Live
                        </UiButton>
                    </span>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
