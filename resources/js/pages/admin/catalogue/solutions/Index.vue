<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Eye, EyeOff, Pencil, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiDropdownItem from '@/components/UI/UiDropdownItem.vue';

defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
});

function togglePublished(id) {
    router.post(`/admin/solutions/${id}/publish`, {}, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <Head title="Solutions" />

    <AppLayout title="Solutions" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Solutions' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Solutions catalogue"
                description="What the public catalogue renders. Publishing here changes the site immediately."
            >
                <template #actions>
                    <UiButton href="/solutions" :inertia="false" variant="ghost" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        View the site
                    </UiButton>
                    <UiButton href="/admin/solutions/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New solution
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="In the catalogue" :value="counts.all ?? 0" />
                <StatTile label="Live on the site" :value="counts.published ?? 0" tone="success" />
                <StatTile label="Featured" :value="counts.featured ?? 0" tone="brand" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search solutions…"
                empty-title="No solutions match"
                empty-description="Try clearing the filters, or add one."
                :only="['table']"
            >
                <template #cell:title="{ row }">
                    <Link :href="`/admin/solutions/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.title }}
                    </Link>
                </template>

                <template #cell:state="{ row }">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <UiBadge :tone="row.published ? 'success' : 'neutral'" size="sm" dot>{{ row.state }}</UiBadge>
                        <UiBadge v-if="row.featured" tone="brand" size="sm">featured</UiBadge>
                    </span>
                </template>

                <template #rowActions="{ row }">
                    <UiDropdown>
                        <template #trigger>
                            <UiButton variant="ghost" size="xs" icon aria-label="Actions">
                                <Pencil class="h-3.5 w-3.5" />
                            </UiButton>
                        </template>

                        <UiDropdownItem :href="`/admin/solutions/${row.id}/edit`">
                            <Pencil class="h-4 w-4" /> Edit
                        </UiDropdownItem>
                        <UiDropdownItem v-if="row.published" :href="`/solutions/${row.slug}`" :inertia="false">
                            <ExternalLink class="h-4 w-4" /> View on the site
                        </UiDropdownItem>
                        <UiDropdownItem @click="togglePublished(row.id)">
                            <component :is="row.published ? EyeOff : Eye" class="h-4 w-4" />
                            {{ row.published ? 'Take off the site' : 'Publish' }}
                        </UiDropdownItem>
                    </UiDropdown>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
