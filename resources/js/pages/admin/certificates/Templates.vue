<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, FileText } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    courses: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Certificate designs" />

    <AppLayout title="Certificate designs" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Certificate designs' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Certificate designs"
                description="How a course's certificates look: background, wording and who signs. A design with no course is the default for every course without its own. Certificates already issued keep the look they were issued with."
            >
                <template #actions>
                    <UiButton href="/admin/certificate-templates/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New design
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable
                :table="table"
                search-placeholder="Search designs or courses…"
                empty-title="No designs yet"
                empty-description="Until there is one, certificates use the built-in design."
            >
                <template #cell:name="{ row }">
                    <Link :href="`/admin/certificate-templates/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.name }}
                    </Link>
                </template>

                <template #cell:course="{ row }">
                    <span :style="row.isDefault ? { color: 'var(--text-muted)' } : {}">{{ row.course }}</span>
                </template>

                <template #cell:is_active="{ row }">
                    <UiBadge :tone="row.is_active ? 'success' : 'neutral'" size="sm" dot>{{ row.is_active ? 'active' : 'off' }}</UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton :href="`/admin/certificate-templates/${row.id}/preview`" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                        <template #leading><FileText class="h-3 w-3" /></template>
                        Preview
                    </UiButton>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
