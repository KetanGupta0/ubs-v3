<script setup>
/**
 * Writing a page: its details, then a list of blocks in order.
 *
 * Each block type has its own few fields and nothing else, which is what
 * keeps a page from being designed into something the site cannot show.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowUp, ArrowDown, Trash2, Heading, AlignLeft, Image, Megaphone, HelpCircle, Plus, Eye, EyeOff,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

const props = defineProps({
    page: { type: Object, default: null },
    blockTypes: { type: Array, default: () => [] },
});

const kinds = {
    heading: { label: 'Heading', icon: Heading, make: () => ({ type: 'heading', text: '', level: 2 }) },
    text: { label: 'Text', icon: AlignLeft, make: () => ({ type: 'text', markdown: '' }) },
    image: { label: 'Picture', icon: Image, make: () => ({ type: 'image', src: null, alt: '', caption: '', upload: null, previewUrl: null }) },
    callout: { label: 'Call to action', icon: Megaphone, make: () => ({ type: 'callout', title: '', body: '', button_label: '', button_href: '' }) },
    faq: { label: 'Questions', icon: HelpCircle, make: () => ({ type: 'faq', items: [{ question: '', answer: '' }] }) },
};

const form = useForm({
    title: props.page?.title ?? '',
    slug: props.page?.slug ?? '',
    summary: props.page?.summary ?? '',
    seo_title: props.page?.seo_title ?? '',
    seo_description: props.page?.seo_description ?? '',
    is_indexable: props.page?.is_indexable ?? true,
    publish: false,
    blocks: (props.page?.blocks ?? []).map((block) => ({ ...kinds[block.type].make(), ...block, upload: null })),
});

const slugPreview = computed(() => (form.slug || form.title)
    .toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''));
const descriptionLength = computed(() => [...(form.seo_description ?? '')].length);

const err = (index, field) => form.errors[`blocks.${index}.${field}`];

const add = (type) => form.blocks.push(kinds[type].make());
const remove = (index) => form.blocks.splice(index, 1);
function move(index, by) {
    const target = index + by;
    if (target < 0 || target >= form.blocks.length) return;
    const [block] = form.blocks.splice(index, 1);
    form.blocks.splice(target, 0, block);
}

function choosePicture(block, event) {
    const file = event.target.files?.[0] ?? null;
    block.upload = file;
    block.previewUrl = file ? URL.createObjectURL(file) : block.previewUrl;
}

function save(publish) {
    form.publish = publish;
    form.post(props.page ? `/admin/pages/${props.page.id}` : '/admin/pages', {
        forceFormData: true,
        preserveScroll: true,
    });
}

const unpublish = () => router.post(`/admin/pages/${props.page.id}/unpublish`, {}, { preserveScroll: true });

function destroy() {
    if (window.confirm(`Delete "${props.page.title}"? If it is published it disappears from the site.`)) {
        router.delete(`/admin/pages/${props.page.id}`);
    }
}

const adding = ref(false);
</script>

<template>
    <Head :title="page ? page.title : 'New page'" />

    <AppLayout
        :title="page ? page.title : 'New page'"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Pages', href: '/admin/pages' }, { label: page ? page.title : 'New' }]"
    >
        <div class="mx-auto max-w-3xl">
            <PageHeader :title="page ? page.title : 'New page'">
                <template #actions>
                    <UiButton href="/admin/pages" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Pages
                    </UiButton>
                    <UiButton v-if="page" :href="`/admin/pages/${page.id}/preview`" :inertia="false" target="_blank" rel="noopener" variant="secondary" size="sm">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                </template>
            </PageHeader>

            <p v-if="page" class="-mt-2 mb-5 flex flex-wrap items-center gap-2 text-sm" style="color: var(--text-muted)">
                <UiBadge :tone="page.published ? 'success' : 'neutral'" size="sm" dot>{{ page.published ? 'published' : 'draft' }}</UiBadge>
                <template v-if="page.published">Live at <a :href="page.path" target="_blank" rel="noopener" class="underline">{{ page.path }}</a> since {{ page.publishedAt }}.</template>
                <template v-else>Only staff can see it, through Preview.</template>
            </p>

            <form class="space-y-5" @submit.prevent="save(false)">
                <FormSection title="The page">
                    <UiFormField label="Title" required :error="form.errors.title">
                        <UiInput v-model="form.title" placeholder="Campus hiring drive, December 2026" />
                    </UiFormField>
                    <UiFormField label="Address" :hint="`It will be at /pages/${slugPreview || 'its-address'}`" :error="form.errors.slug">
                        <UiInput v-model="form.slug" placeholder="Made from the title if left empty" />
                    </UiFormField>
                    <UiFormField label="Summary" hint="One or two sentences under the title." :error="form.errors.summary">
                        <UiTextarea v-model="form.summary" :rows="2" />
                    </UiFormField>
                </FormSection>

                <FormSection title="Blocks" description="The page, top to bottom. Text is written as Markdown: **bold**, *italic*, [a link](https://…), lists with - at the start of a line.">
                    <p v-if="!form.blocks.length" class="text-sm" style="color: var(--text-muted)">No blocks yet. Add the first one below.</p>

                    <div
                        v-for="(block, index) in form.blocks"
                        :key="index"
                        class="rounded-xl border p-4"
                        style="border-color: var(--border-subtle)"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="inline-flex items-center gap-2 text-sm font-medium" style="color: var(--text-strong)">
                                <component :is="kinds[block.type].icon" class="h-4 w-4" style="color: var(--text-muted)" />
                                {{ kinds[block.type].label }}
                            </span>
                            <span class="flex gap-1">
                                <UiButton variant="ghost" size="xs" icon aria-label="Move up" :disabled="index === 0" @click="move(index, -1)"><ArrowUp class="h-3.5 w-3.5" /></UiButton>
                                <UiButton variant="ghost" size="xs" icon aria-label="Move down" :disabled="index === form.blocks.length - 1" @click="move(index, 1)"><ArrowDown class="h-3.5 w-3.5" /></UiButton>
                                <UiButton variant="ghost" size="xs" icon aria-label="Remove block" @click="remove(index)"><Trash2 class="h-3.5 w-3.5" /></UiButton>
                            </span>
                        </div>

                        <div v-if="block.type === 'heading'" class="grid gap-3 sm:grid-cols-[1fr_9rem]">
                            <UiFormField label="Heading" :error="err(index, 'text')"><UiInput v-model="block.text" /></UiFormField>
                            <UiFormField label="Size">
                                <UiSelect v-model="block.level" :options="[{ value: 2, label: 'Section' }, { value: 3, label: 'Sub-section' }]" />
                            </UiFormField>
                        </div>

                        <UiFormField v-else-if="block.type === 'text'" label="Text" :error="err(index, 'markdown')">
                            <UiTextarea v-model="block.markdown" :rows="6" />
                        </UiFormField>

                        <div v-else-if="block.type === 'image'" class="space-y-3">
                            <img v-if="block.previewUrl" :src="block.previewUrl" alt="" class="max-h-48 rounded-lg border" style="border-color: var(--border-subtle)">
                            <UiFormField label="Picture" hint="JPG, PNG or WebP under 5 MB. It is resized to 1600 px wide and its metadata removed." :error="err(index, 'upload')">
                                <input type="file" accept="image/jpeg,image/png,image/webp" class="block text-sm" @change="choosePicture(block, $event)">
                            </UiFormField>
                            <UiFormField label="Description for screen readers" required :error="err(index, 'alt')">
                                <UiInput v-model="block.alt" placeholder="Students presenting their projects to the panel" />
                            </UiFormField>
                            <UiFormField label="Caption" :error="err(index, 'caption')"><UiInput v-model="block.caption" /></UiFormField>
                        </div>

                        <div v-else-if="block.type === 'callout'" class="space-y-3">
                            <UiFormField label="Title" :error="err(index, 'title')"><UiInput v-model="block.title" /></UiFormField>
                            <UiFormField label="Text" :error="err(index, 'body')"><UiTextarea v-model="block.body" :rows="2" /></UiFormField>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <UiFormField label="Button words" :error="err(index, 'button_label')"><UiInput v-model="block.button_label" placeholder="Register now" /></UiFormField>
                                <UiFormField label="Button goes to" hint="/contact, or https://…" :error="err(index, 'button_href')"><UiInput v-model="block.button_href" /></UiFormField>
                            </div>
                        </div>

                        <div v-else-if="block.type === 'faq'" class="space-y-3">
                            <p v-if="err(index, 'items')" class="text-sm" style="color: var(--color-danger-600)">{{ err(index, 'items') }}</p>
                            <div v-for="(item, i) in block.items" :key="i" class="space-y-2 rounded-lg p-3" style="background: var(--surface-sunken)">
                                <UiFormField :label="`Question ${i + 1}`" :error="form.errors[`blocks.${index}.items.${i}.question`]"><UiInput v-model="item.question" /></UiFormField>
                                <UiFormField label="Answer" :error="form.errors[`blocks.${index}.items.${i}.answer`]"><UiTextarea v-model="item.answer" :rows="2" /></UiFormField>
                                <UiButton v-if="block.items.length > 1" variant="ghost" size="xs" @click="block.items.splice(i, 1)">Remove this question</UiButton>
                            </div>
                            <UiButton variant="ghost" size="xs" @click="block.items.push({ question: '', answer: '' })">
                                <template #leading><Plus class="h-3 w-3" /></template>
                                Another question
                            </UiButton>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <UiButton v-for="(kind, type) in kinds" :key="type" variant="secondary" size="xs" @click="add(type)">
                            <template #leading><component :is="kind.icon" class="h-3 w-3" /></template>
                            {{ kind.label }}
                        </UiButton>
                    </div>
                </FormSection>

                <FormSection title="Search engines">
                    <UiFormField label="Title in search results" hint="Leave empty to use the page title." :error="form.errors.seo_title">
                        <UiInput v-model="form.seo_title" />
                    </UiFormField>
                    <UiFormField
                        label="Description in search results"
                        :hint="`${descriptionLength} of 158 characters. Leave empty to use the summary.`"
                        :error="form.errors.seo_description"
                    >
                        <UiTextarea v-model="form.seo_description" :rows="2" />
                    </UiFormField>
                    <UiSwitch v-model="form.is_indexable" label="Let search engines list this page" description="Turn off for a page meant only for people you send the link to." />
                </FormSection>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="flex gap-2">
                        <UiButton v-if="page" variant="ghost" size="sm" @click="destroy">
                            <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                            Delete
                        </UiButton>
                        <UiButton v-if="page?.published" variant="ghost" size="sm" @click="unpublish">
                            <template #leading><EyeOff class="h-3.5 w-3.5" /></template>
                            Take off the site
                        </UiButton>
                    </span>
                    <span class="flex gap-2">
                        <UiButton type="submit" variant="secondary" :loading="form.processing && !form.publish">
                            {{ page?.published ? 'Save' : 'Save draft' }}
                        </UiButton>
                        <UiButton v-if="!page?.published" :loading="form.processing && form.publish" @click="save(true)">Publish</UiButton>
                    </span>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
