<script setup>
import { Head } from '@inertiajs/vue3';
import {
    ArrowLeft, Download, ExternalLink, FileText, Film, File, FileSpreadsheet, Presentation, FolderOpen,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    course: { type: Object, required: true },
    materials: { type: Array, default: () => [] },
});

const icons = {
    pdf: FileText, video: Film, slides: Presentation, sheet: FileSpreadsheet, link: ExternalLink, file: File,
};
</script>

<template>
    <Head :title="`${course.title} — materials`" />

    <AppLayout
        title="Study materials"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: course.title, href: `/student/courses/${course.id}` },
            { label: 'Materials' },
        ]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader
                title="Study materials"
                description="Everything attached to lessons you can open. Handouts for sealed lessons appear as those open."
            >
                <template #actions>
                    <UiButton :href="`/student/courses/${course.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!materials.length"
                :icon="FolderOpen"
                title="Nothing yet"
                description="Slides and handouts appear here as the course goes on."
            />

            <ul v-else class="space-y-2">
                <li
                    v-for="material in materials"
                    :key="material.id"
                    class="flex items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-3.5"
                    style="border-color: var(--border-subtle)"
                >
                    <span
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                        style="background: var(--surface-sunken); color: var(--text-muted)"
                    >
                        <component :is="icons[material.kind] ?? File" class="h-4 w-4" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{ material.title }}</span>
                        <span class="block text-xs" style="color: var(--text-muted)">
                            {{ [material.lesson, material.description, material.size].filter(Boolean).join(' · ') }}
                        </span>
                    </span>

                    <UiButton v-if="material.isLink" :href="material.url" :inertia="false" target="_blank" variant="ghost" size="xs">
                        <template #leading><ExternalLink class="h-3 w-3" /></template>
                        Open
                    </UiButton>
                    <UiButton
                        v-else-if="material.downloadable"
                        :href="`/student/courses/${course.id}/materials/${material.id}`"
                        :inertia="false"
                        variant="ghost"
                        size="xs"
                    >
                        <template #leading><Download class="h-3 w-3" /></template>
                        Download
                    </UiButton>
                    <span v-else class="text-xs" style="color: var(--text-muted)">view only</span>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
