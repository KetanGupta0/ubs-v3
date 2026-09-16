<script setup>
/**
 * Uploading and organising client documents.
 *
 * Replacing a file makes a new version rather than overwriting, because the
 * previous version is exactly what somebody asks for when a disagreement
 * starts.
 */
import { ref, watch } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { debounce } from '@/support/debounce';
import {
    Upload, Search, FileText, Image, FileSpreadsheet, FileArchive, File,
    Download, Trash2, EyeOff, FolderPlus, Pencil,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiPagination from '@/components/UI/UiPagination.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    documents: { type: Object, required: true },
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    folders: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const icons = { image: Image, pdf: FileText, sheet: FileSpreadsheet, doc: FileText, archive: FileArchive, file: File };

const search = ref(props.filters.q ?? '');
const client = ref(props.filters.client ?? '');
const project = ref(props.filters.project ?? '');

function reload() {
    router.get('/admin/documents', {
        q: search.value || undefined,
        client: client.value || undefined,
        project: project.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

watch(search, debounce(reload, 300));
watch([client, project], reload);

/* ------------------------------------------------------------- uploading */

const uploadOpen = ref(false);
const replacing = ref(null);

// UiFileDrop hands back a list even when it is holding one file, so the picked
// file is kept here and copied onto the form at submit.
const chosen = ref([]);

const uploadForm = useForm({
    file: null,
    client_id: null,
    project_id: null,
    folder_id: null,
    name: '',
    description: '',
    category: '',
    visible_to_client: true,
    supersedes_id: null,
});

function openUpload(document = null) {
    replacing.value = document;
    chosen.value = [];
    uploadForm.reset();
    uploadForm.clearErrors();
    uploadForm.client_id = document?.clientId ?? (client.value || null);
    uploadForm.supersedes_id = document?.id ?? null;
    uploadForm.name = document?.name ?? '';
    uploadOpen.value = true;
}

function upload() {
    uploadForm.file = chosen.value[0] ?? null;

    uploadForm.post('/admin/documents', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            uploadOpen.value = false;
            replacing.value = null;
            chosen.value = [];
            uploadForm.reset();
        },
    });
}

/* --------------------------------------------------------------- editing */

const editing = ref(null);
const editForm = useForm({ name: '', description: '', category: '', folder_id: null, visible_to_client: true });

function startEdit(document) {
    editing.value = document;
    editForm.clearErrors();
    editForm.name = document.name;
    editForm.description = document.description ?? '';
    editForm.category = document.category ?? '';
    editForm.folder_id = null;
    editForm.visible_to_client = document.visibleToClient;
}

function saveEdit() {
    editForm.put(`/admin/documents/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function destroy(document) {
    if (confirm(`Remove "${document.name}" from the client's view?`)) {
        router.delete(`/admin/documents/${document.id}`, { preserveScroll: true });
    }
}

/* --------------------------------------------------------------- folders */

const folderOpen = ref(false);
const folderForm = useForm({ client_id: null, project_id: null, parent_id: null, name: '' });

function createFolder() {
    folderForm.post('/admin/document-folders', {
        preserveScroll: true,
        onSuccess: () => {
            folderOpen.value = false;
            folderForm.reset();
        },
    });
}
</script>

<template>
    <Head title="Documents" />

    <AppLayout title="Documents" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Documents' }]">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader
                title="Client documents"
                description="Proposals, specifications, reports and handover files. Replacing one keeps the version it replaced."
            >
                <template #actions>
                    <UiButton variant="secondary" size="sm" @click="folderOpen = true">
                        <template #leading><FolderPlus class="h-3.5 w-3.5" /></template>
                        New folder
                    </UiButton>
                    <UiButton size="sm" @click="openUpload()">
                        <template #leading><Upload class="h-3.5 w-3.5" /></template>
                        Upload
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap gap-2">
                <div class="min-w-[200px] flex-1">
                    <UiInput v-model="search" placeholder="Search documents…" aria-label="Search documents">
                        <template #leading><Search class="h-4 w-4" /></template>
                    </UiInput>
                </div>
                <div class="w-full sm:w-52">
                    <UiSelect v-model="client" :options="[{ value: '', label: 'All clients' }, ...clients]" aria-label="Filter by client" />
                </div>
                <div class="w-full sm:w-52">
                    <UiSelect
                        v-model="project"
                        :options="[{ value: '', label: 'All projects' }, ...projects.filter((p) => !client || p.clientId === Number(client))]"
                        aria-label="Filter by project"
                    />
                </div>
            </div>

            <UiEmptyState
                v-if="!documents.data.length"
                :icon="FileText"
                title="Nothing here"
                description="Try clearing the filters, or upload something."
            />

            <ul v-else class="space-y-2">
                <li
                    v-for="document in documents.data"
                    :key="document.id"
                    class="flex flex-wrap items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-3.5"
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
                            <UiBadge v-if="document.version > 1" size="sm">v{{ document.version }}</UiBadge>
                            <UiBadge v-if="!document.visibleToClient" tone="warning" size="sm">
                                <EyeOff class="mr-1 h-3 w-3" />hidden
                            </UiBadge>
                        </span>
                        <span class="mt-0.5 block text-xs" style="color: var(--text-muted)">
                            {{ [document.client, document.project, document.size, document.at].filter(Boolean).join(' · ') }}
                        </span>
                        <span v-if="document.viewedByClient" class="block text-xs" style="color: var(--text-muted)">
                            Opened by the client {{ document.viewedByClient }}
                        </span>
                    </span>

                    <span class="flex shrink-0 items-center gap-0.5">
                        <UiButton variant="ghost" size="xs" @click="openUpload(document)">
                            <template #leading><Upload class="h-3 w-3" /></template>
                            <span class="hidden sm:contents">Replace</span>
                        </UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Edit details" @click="startEdit(document)">
                            <Pencil class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton
                            :href="`/admin/documents/${document.id}/download`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                            icon
                            aria-label="Download"
                        >
                            <Download class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Remove" @click="destroy(document)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </span>
                </li>
            </ul>

            <UiPagination v-if="documents.links" :links="documents.links" :meta="documents" />
        </div>

        <!-- ------------------------------------------------------- upload -->
        <UiModal
            :open="uploadOpen"
            :title="replacing ? `Replace ${replacing.name}` : 'Upload a document'"
            :description="replacing
                ? 'The current file becomes version history. It stays downloadable.'
                : 'Up to 25 MB. Anything larger, send a link instead.'"
            size="lg"
            @close="uploadOpen = false"
        >
            <form id="upload-form" class="space-y-4" @submit.prevent="upload">
                <UiFormField label="File" required :error="uploadForm.errors.file">
                    <UiFileDrop v-model="chosen" :max-size="25" hint="Up to 25 MB" />
                </UiFormField>

                <UiFormField v-if="!replacing" label="Client" required :error="uploadForm.errors.client_id">
                    <UiCombobox v-model="uploadForm.client_id" :options="clients" placeholder="Search clients…" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" :error="uploadForm.errors.name" hint="Left blank, the filename is used.">
                        <UiInput v-model="uploadForm.name" />
                    </UiFormField>

                    <UiFormField label="Category" :error="uploadForm.errors.category" hint="Proposal, report, handover…">
                        <UiInput v-model="uploadForm.category" />
                    </UiFormField>

                    <UiFormField v-if="!replacing" label="Project" :error="uploadForm.errors.project_id">
                        <UiSelect
                            v-model="uploadForm.project_id"
                            :options="[
                                { value: '', label: 'Not tied to one' },
                                ...projects.filter((p) => !uploadForm.client_id || p.clientId === Number(uploadForm.client_id)),
                            ]"
                        />
                    </UiFormField>

                    <UiFormField v-if="!replacing" label="Folder" :error="uploadForm.errors.folder_id">
                        <UiSelect
                            v-model="uploadForm.folder_id"
                            :options="[
                                { value: '', label: 'No folder' },
                                ...folders.filter((f) => !uploadForm.client_id || f.clientId === Number(uploadForm.client_id)),
                            ]"
                        />
                    </UiFormField>
                </div>

                <UiFormField label="Description" :error="uploadForm.errors.description">
                    <UiTextarea v-model="uploadForm.description" :rows="2" />
                </UiFormField>

                <UiSwitch
                    v-model="uploadForm.visible_to_client"
                    label="The client can see this"
                    description="Off keeps it internal."
                />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="uploadOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="upload-form" :loading="uploadForm.processing">
                    {{ replacing ? 'Upload the replacement' : 'Upload' }}
                </UiButton>
            </template>
        </UiModal>

        <!-- --------------------------------------------------------- edit -->
        <UiModal :open="Boolean(editing)" title="Document details" @close="editing = null">
            <form id="edit-form" class="space-y-4" @submit.prevent="saveEdit">
                <UiFormField label="Name" required :error="editForm.errors.name">
                    <UiInput v-model="editForm.name" />
                </UiFormField>

                <UiFormField label="Description" :error="editForm.errors.description">
                    <UiTextarea v-model="editForm.description" :rows="2" />
                </UiFormField>

                <UiFormField label="Category" :error="editForm.errors.category">
                    <UiInput v-model="editForm.category" />
                </UiFormField>

                <UiSwitch v-model="editForm.visible_to_client" label="The client can see this" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="editing = null">Cancel</UiButton>
                <UiButton type="submit" form="edit-form" :loading="editForm.processing">Save</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------------- folder -->
        <UiModal :open="folderOpen" title="New folder" @close="folderOpen = false">
            <form id="folder-form" class="space-y-4" @submit.prevent="createFolder">
                <UiFormField label="Client" required :error="folderForm.errors.client_id">
                    <UiCombobox v-model="folderForm.client_id" :options="clients" placeholder="Search clients…" />
                </UiFormField>

                <UiFormField label="Folder name" required :error="folderForm.errors.name">
                    <UiInput v-model="folderForm.name" placeholder="Handover" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="folderOpen = false">Cancel</UiButton>
                <UiButton type="submit" form="folder-form" :loading="folderForm.processing">Create folder</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
