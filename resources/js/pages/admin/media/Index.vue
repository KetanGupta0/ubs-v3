<script setup>
/**
 * The media library: every picture the public site's pages can use.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, PenLine, Trash2, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFileDrop from '@/components/UI/UiFileDrop.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';

defineProps({
    table: { type: Object, required: true },
    summary: { type: Object, required: true },
});

const files = ref([]);
const upload = useForm({ file: null, alt: '' });

function send() {
    upload.file = files.value[0] ?? null;
    upload.post('/admin/media', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            files.value = [];
            upload.reset();
        },
    });
}

/* Changing a description, in a small dialog over the list. */
const editing = ref(null);
const describe = useForm({ alt: '' });

function edit(row) {
    editing.value = row;
    describe.alt = row.alt;
    describe.clearErrors();
}

function saveDescription() {
    describe.put(`/admin/media/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

function remove(row) {
    if (window.confirm(`Delete "${row.alt}" from the library? The file goes too.`)) {
        router.delete(`/admin/media/${row.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Media library" />

    <AppLayout title="Media library" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Media library' }]">
        <div class="mx-auto max-w-6xl space-y-6">
            <PageHeader
                title="Media library"
                :description="`Pictures for the public site's pages: upload once, use on any page. ${summary.count} ${summary.count === 1 ? 'picture' : 'pictures'}, ${summary.size} in all.`"
            />

            <FormSection
                title="Add a picture"
                description="JPG, PNG or WebP under 8 MB. It is resized to at most 1600 px wide and its camera and location details are removed. PNGs stay PNGs, so a transparent logo keeps its transparency."
            >
                <form class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end" @submit.prevent="send">
                    <UiFormField label="Picture" required :error="upload.errors.file">
                        <UiFileDrop v-model="files" accept="image/jpeg,image/png,image/webp" :max-size="8" />
                    </UiFormField>
                    <UiFormField label="Description" required hint="What it shows, for somebody who cannot see it." :error="upload.errors.alt">
                        <UiInput v-model="upload.alt" placeholder="Students presenting their projects in the training hall" />
                    </UiFormField>
                    <UiButton type="submit" :loading="upload.processing">
                        <template #leading><Upload class="h-4 w-4" /></template>
                        Upload
                    </UiButton>
                </form>
            </FormSection>

            <DataTable :table="table" search-placeholder="Search by description or file name…" empty-title="No pictures yet" empty-description="Add the first one above.">
                <template #cell:alt="{ row }">
                    <span class="flex items-center gap-3">
                        <img :src="row.url" :alt="row.alt" loading="lazy" class="h-12 w-16 shrink-0 rounded-md border object-cover" style="border-color: var(--border-subtle)">
                        <span class="line-clamp-2" style="color: var(--text-strong)">{{ row.alt }}</span>
                    </span>
                </template>
                <template #cell:used="{ row }">
                    <span :style="row.usedCount ? {} : { color: 'var(--text-muted)' }">{{ row.used }}</span>
                </template>
                <template #rowActions="{ row }">
                    <span class="flex justify-end gap-1">
                        <UiButton variant="ghost" size="xs" icon :aria-label="`Change the description of ${row.alt}`" @click="edit(row)"><PenLine class="h-3.5 w-3.5" /></UiButton>
                        <UiButton :href="row.url" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs" icon :aria-label="`Open ${row.alt}`"><ExternalLink class="h-3.5 w-3.5" /></UiButton>
                        <UiButton variant="ghost" size="xs" icon :aria-label="`Delete ${row.alt}`" :disabled="row.usedCount > 0" :title="row.usedCount ? 'Shown on a page, so it cannot be deleted' : undefined" @click="remove(row)"><Trash2 class="h-3.5 w-3.5" /></UiButton>
                    </span>
                </template>
            </DataTable>
        </div>

        <UiModal :open="editing !== null" title="Change the description" description="Pages that already use this picture keep the description they were written with." @close="editing = null">
            <form v-if="editing" class="space-y-4" @submit.prevent="saveDescription">
                <img :src="editing.url" :alt="editing.alt" class="max-h-48 rounded-lg border" style="border-color: var(--border-subtle)">
                <UiFormField label="Description" required :error="describe.errors.alt">
                    <UiInput v-model="describe.alt" />
                </UiFormField>
                <div class="flex justify-end gap-2">
                    <UiButton variant="ghost" @click="editing = null">Cancel</UiButton>
                    <UiButton type="submit" :loading="describe.processing">Save</UiButton>
                </div>
            </form>
        </UiModal>
    </AppLayout>
</template>
