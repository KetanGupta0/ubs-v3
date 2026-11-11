<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });
</script>

<template>
    <Head title="Team and mentors" />

    <AppLayout title="Team and mentors" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Team and mentors' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Team and mentors"
                description="The people on the About page and the mentors on the Training page. Real people only: with nobody added, each section stays off the site."
            >
                <template #actions>
                    <UiButton href="/admin/team/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Add a person
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by name or role…" empty-title="Nobody added yet" empty-description="Add the first person with Add a person.">
                <template #cell:name="{ row }">
                    <Link :href="`/admin/team/${row.id}/edit`" class="flex items-center gap-3 font-medium hover:underline" style="color: var(--text-strong)">
                        <UiAvatar :name="row.name" :src="row.photo" size="sm" />
                        {{ row.name }}
                    </Link>
                </template>
                <template #cell:is_active="{ row }">
                    <UiBadge :tone="row.is_active ? 'success' : 'neutral'" size="sm" dot>{{ row.is_active ? 'shown' : 'hidden' }}</UiBadge>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
