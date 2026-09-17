<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Eye, EyeOff, Pencil, ExternalLink, CalendarPlus, ListTree, ClipboardList, FileText } from 'lucide-vue-next';

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

const typeTones = { internship: 'brand', programme: 'accent', course: 'neutral' };

function togglePublished(id) {
    router.post(`/admin/courses/${id}/publish`, {}, { preserveScroll: true, preserveState: true });
}

function publicPath(row) {
    return row.type === 'internship' ? `/internships/${row.slug}` : `/training/${row.slug}`;
}
</script>

<template>
    <Head title="Courses and internships" />

    <AppLayout title="Courses and internships" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Courses' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Courses and internships"
                description="They share a table because they are the same shape. The type decides which public path serves it."
            >
                <template #actions>
                    <UiButton href="/admin/courses/new?type=internship" size="sm" variant="secondary">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New internship
                    </UiButton>
                    <UiButton href="/admin/courses/new?type=course" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New course
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Everything" :value="counts.all ?? 0" />
                <StatTile label="Internships" :value="counts.internships ?? 0" tone="brand" />
                <StatTile label="Courses" :value="counts.courses ?? 0" />
                <StatTile
                    label="Inside the LMS only"
                    :value="counts.hidden ?? 0"
                    hint="Never shown on the public site"
                />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search courses and internships…"
                empty-title="Nothing matches"
                empty-description="Try clearing the filters, or add one."
                :only="['table']"
            >
                <template #cell:title="{ row }">
                    <Link :href="`/admin/courses/${row.id}/edit`" class="font-medium hover:underline" style="color: var(--text-strong)">
                        {{ row.title }}
                    </Link>
                </template>

                <template #cell:type="{ value }">
                    <UiBadge :tone="typeTones[value] ?? 'neutral'" size="sm">{{ value }}</UiBadge>
                </template>

                <template #cell:state="{ row }">
                    <UiBadge :tone="row.state === 'Live' ? 'success' : row.state === 'LMS only' ? 'warning' : 'neutral'" size="sm" dot>
                        {{ row.state }}
                    </UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiDropdown>
                        <template #trigger>
                            <UiButton variant="ghost" size="xs" icon aria-label="Actions">
                                <Pencil class="h-3.5 w-3.5" />
                            </UiButton>
                        </template>

                        <UiDropdownItem :href="`/admin/courses/${row.id}/edit`">
                            <Pencil class="h-4 w-4" /> Edit
                        </UiDropdownItem>
                        <UiDropdownItem :href="`/admin/courses/${row.id}/builder`">
                            <ListTree class="h-4 w-4" /> Modules and lessons
                        </UiDropdownItem>
                        <UiDropdownItem :href="`/admin/courses/${row.id}/quizzes`">
                            <ClipboardList class="h-4 w-4" /> Quizzes
                        </UiDropdownItem>
                        <UiDropdownItem :href="`/admin/courses/${row.id}/assignments`">
                            <FileText class="h-4 w-4" /> Assignments
                        </UiDropdownItem>
                        <UiDropdownItem :href="`/admin/batches/new?course=${row.id}`">
                            <CalendarPlus class="h-4 w-4" /> Add a batch
                        </UiDropdownItem>
                        <UiDropdownItem v-if="row.state === 'Live'" :href="publicPath(row)" :inertia="false">
                            <ExternalLink class="h-4 w-4" /> View on the site
                        </UiDropdownItem>
                        <UiDropdownItem @click="togglePublished(row.id)">
                            <component :is="row.published ? EyeOff : Eye" class="h-4 w-4" />
                            {{ row.published ? 'Unpublish' : 'Publish' }}
                        </UiDropdownItem>
                    </UiDropdown>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
