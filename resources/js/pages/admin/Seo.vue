<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, Pencil } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';

defineProps({ table: { type: Object, required: true } });

const current = ref(null);
const picture = ref(null);
const form = useForm({ title: '', description: '', noindex: false, image: null, remove_image: false });

const titleLength = computed(() => [...(form.title ?? '')].length);
const descriptionLength = computed(() => [...(form.description ?? '')].length);

function open(row) {
    current.value = row;
    form.title = row.form.title ?? '';
    form.description = row.form.description ?? '';
    form.noindex = row.form.noindex;
    form.image = null;
    form.remove_image = false;
    form.clearErrors();
    picture.value = null;
}

function save() {
    form.image = picture.value;
    form.post(`/admin/seo/${current.value.id}`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { current.value = null; },
    });
}
</script>

<template>
    <Head title="Search wording" />

    <AppLayout title="Search wording" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Search wording' }]">
        <div class="mx-auto max-w-6xl">
            <PageHeader
                title="Search wording"
                description="What a search result and a shared link show for the site's fixed pages. Leave a field empty to keep the page's own wording. Pages you write, and courses, solutions and services, have their own."
            />

            <DataTable :table="table" search-placeholder="Search pages…" empty-title="Nothing matches">
                <template #cell:path="{ row }">
                    <code class="text-xs" style="color: var(--text-muted)">{{ row.path }}</code>
                </template>
                <template #cell:state="{ row }">
                    <UiBadge :tone="row.state === 'Page default' ? 'neutral' : 'brand'" size="sm">{{ row.state }}</UiBadge>
                </template>
                <template #rowActions="{ row }">
                    <span class="flex justify-end gap-1">
                        <UiButton :href="row.path" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs" icon :aria-label="`Open ${row.label}`">
                            <ExternalLink class="h-3.5 w-3.5" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="open(row)">
                            <template #leading><Pencil class="h-3 w-3" /></template>
                            Edit
                        </UiButton>
                    </span>
                </template>
            </DataTable>
        </div>

        <UiModal :open="current !== null" :title="current ? `${current.label} in search results` : ''" :description="current?.path" size="lg" @close="current = null">
            <form v-if="current" id="seo-form" class="space-y-4" @submit.prevent="save">
                <UiFormField label="Title" :hint="`${titleLength} characters; about 60 show in a result. Empty keeps the page's own.`" :error="form.errors.title">
                    <UiInput v-model="form.title" />
                </UiFormField>
                <UiFormField label="Description" :hint="`${descriptionLength} of 158 characters. Empty keeps the page's own.`" :error="form.errors.description">
                    <UiTextarea v-model="form.description" :rows="3" />
                </UiFormField>
                <UiFormField label="Picture for shared links" hint="1200 × 630 works everywhere. Empty keeps the site's default." :error="form.errors.image">
                    <img v-if="current.form.imageUrl && !form.remove_image" :src="current.form.imageUrl" alt="" class="mb-2 max-h-32 rounded-lg border" style="border-color: var(--border-subtle)">
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="block text-sm" @change="picture = $event.target.files?.[0] ?? null">
                </UiFormField>
                <UiCheckbox v-if="current.form.imageUrl" v-model="form.remove_image" label="Remove the current picture" />
                <UiSwitch v-model="form.noindex" label="Keep this page out of search engines" />
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="current = null">Cancel</UiButton>
                <UiButton type="submit" form="seo-form" :loading="form.processing">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
