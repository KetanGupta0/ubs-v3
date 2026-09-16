<script setup>
/**
 * The document repository.
 *
 * Nothing here links straight to storage: every file goes through a route that
 * checks who is asking. A folder tree that filters a list the server already
 * scoped is a browsing aid, not the access control.
 */
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { debounce } from '@/support/debounce';
import {
    Folder, FolderOpen, FileText, Image, FileSpreadsheet, FileArchive, File,
    Download, Eye, Search, History,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const icons = { image: Image, pdf: FileText, sheet: FileSpreadsheet, doc: FileText, archive: FileArchive, file: File };

const search = ref(props.filters.q ?? '');
const project = ref(props.filters.project ?? '');
const folder = ref(props.filters.folder ?? null);

function reload() {
    router.get('/client/documents', {
        q: search.value || undefined,
        project: project.value || undefined,
        folder: folder.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

watch(search, debounce(reload, 300));
watch(project, reload);

function openFolder(id) {
    folder.value = folder.value === id ? null : id;
    reload();
}
</script>

<template>
    <Head title="Documents" />

    <AppLayout title="Documents" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Documents' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Documents"
                description="Everything we have shared with you: proposals, specifications, reports and handover files."
            />

            <div class="grid gap-5 lg:grid-cols-4">
                <!-- ------------------------------------------------ folders -->
                <aside class="lg:col-span-1">
                    <UiCard padding="p-4">
                        <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                            Folders
                        </h2>

                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition"
                            :class="folder === null && 'bg-[var(--surface-sunken)] font-medium'"
                            @click="openFolder(null)"
                        >
                            <FolderOpen class="h-4 w-4" style="color: var(--text-muted)" />
                            Everything
                        </button>

                        <ul class="mt-1 space-y-0.5">
                            <li v-for="node in folders" :key="node.id">
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-[var(--surface-sunken)]"
                                    :class="folder === node.id && 'bg-[var(--surface-sunken)] font-medium'"
                                    @click="openFolder(node.id)"
                                >
                                    <Folder class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                                    <span class="min-w-0 flex-1 truncate">{{ node.name }}</span>
                                    <span class="text-xs tnum" style="color: var(--text-muted)">{{ node.count }}</span>
                                </button>

                                <ul v-if="node.children.length" class="ml-4 mt-0.5 space-y-0.5">
                                    <li v-for="child in node.children" :key="child.id">
                                        <button
                                            type="button"
                                            class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-[var(--surface-sunken)]"
                                            :class="folder === child.id && 'bg-[var(--surface-sunken)] font-medium'"
                                            @click="openFolder(child.id)"
                                        >
                                            <Folder class="h-3.5 w-3.5 shrink-0" style="color: var(--text-muted)" />
                                            <span class="min-w-0 flex-1 truncate">{{ child.name }}</span>
                                            <span class="text-xs tnum" style="color: var(--text-muted)">{{ child.count }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </li>
                        </ul>

                        <p v-if="!folders.length" class="px-2 text-xs" style="color: var(--text-muted)">
                            No folders yet.
                        </p>
                    </UiCard>
                </aside>

                <!-- ------------------------------------------------- files -->
                <div class="lg:col-span-3 space-y-4">
                    <div class="flex flex-wrap gap-2">
                        <div class="min-w-[200px] flex-1">
                            <UiInput v-model="search" placeholder="Search documents…" aria-label="Search documents">
                                <template #leading><Search class="h-4 w-4" /></template>
                            </UiInput>
                        </div>
                        <div class="w-full sm:w-52">
                            <UiSelect
                                v-model="project"
                                :options="[{ value: '', label: 'All projects' }, ...projects]"
                                aria-label="Filter by project"
                            />
                        </div>
                    </div>

                    <UiEmptyState
                        v-if="!documents.length"
                        :icon="FileText"
                        title="Nothing here"
                        description="Try clearing the filters, or check back after the next handover."
                    />

                    <ul v-else class="space-y-2">
                        <li
                            v-for="document in documents"
                            :key="document.id"
                            class="flex flex-wrap items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-3.5 transition
                                   hover:shadow-[var(--shadow-card)]"
                            style="border-color: var(--border-subtle)"
                        >
                            <span
                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                                style="background: var(--surface-sunken); color: var(--text-muted)"
                            >
                                <component :is="icons[document.kind] ?? File" class="h-4 w-4" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-medium">{{ document.name }}</span>
                                    <UiBadge v-if="document.isNew" tone="brand" size="sm">new</UiBadge>
                                    <UiBadge v-if="document.version > 1" size="sm">v{{ document.version }}</UiBadge>
                                </span>
                                <span class="mt-0.5 block text-xs" style="color: var(--text-muted)">
                                    {{ [document.project, document.category, document.size, document.at].filter(Boolean).join(' · ') }}
                                </span>
                            </span>

                            <span class="flex shrink-0 items-center gap-1">
                                <UiButton
                                    v-if="document.previewable"
                                    :href="`/client/documents/${document.id}/preview`"
                                    :inertia="false"
                                    target="_blank"
                                    variant="ghost"
                                    size="xs"
                                    icon
                                    aria-label="Preview"
                                >
                                    <Eye class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton
                                    v-if="document.version > 1"
                                    :href="`/client/documents/${document.id}/versions`"
                                    variant="ghost"
                                    size="xs"
                                    icon
                                    aria-label="Earlier versions"
                                >
                                    <History class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton
                                    :href="`/client/documents/${document.id}/download`"
                                    :inertia="false"
                                    variant="ghost"
                                    size="xs"
                                >
                                    <template #leading><Download class="h-3.5 w-3.5" /></template>
                                    <span class="hidden sm:contents">Download</span>
                                </UiButton>
                            </span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
