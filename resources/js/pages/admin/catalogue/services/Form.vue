<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Trash2, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';

const props = defineProps({
    service: { type: Object, default: null },
    types: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.service));

const form = useForm({
    title: props.service?.title ?? '',
    slug: props.service?.slug ?? '',
    type: props.service?.type ?? 'development',
    tagline: props.service?.tagline ?? '',
    summary: props.service?.summary ?? '',
    description: props.service?.description ?? '',
    deliverables: props.service?.deliverables ?? [],
    engagement_models: props.service?.engagement_models ?? [],
    process: props.service?.process ?? [],
    faqs: props.service?.faqs ?? [],
    icon: props.service?.icon ?? '',
    sort_order: props.service?.sort_order ?? 0,
    is_published: props.service?.is_published ?? false,
});

function submit() {
    isEdit.value
        ? form.put(`/admin/services/${props.service.id}`, { preserveScroll: true })
        : form.post('/admin/services');
}

function destroy() {
    if (confirm('Delete this service page?')) {
        router.delete(`/admin/services/${props.service.id}`);
    }
}
</script>

<template>
    <Head :title="isEdit ? service.title : 'New service'" />

    <AppLayout
        :title="isEdit ? service.title : 'New service'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Services', href: '/admin/services' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="isEdit ? service.title : 'New service'">
                <template #actions>
                    <UiButton href="/admin/services" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                    <UiButton v-if="isEdit && service.is_published" :href="service.publicUrl" :inertia="false" variant="ghost" size="sm">
                        <template #leading><ExternalLink class="h-3.5 w-3.5" /></template>
                        View live
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="The basics">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Title" required :error="form.errors.title">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.title" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField label="Type" required :error="form.errors.type">
                        <template #default="field">
                            <UiSelect :id="field.id" v-model="form.type" :options="types.map((t) => ({ value: t, label: t }))" />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="URL slug" :error="form.errors.slug">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.slug" class="font-mono" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Icon name" hint="A Lucide icon, for example Code2." :error="form.errors.icon">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.icon" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Tagline" required :error="form.errors.tagline">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.tagline" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField label="Summary" required :error="form.errors.summary">
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.summary" :rows="3" :invalid="field.invalid" required />
                    </template>
                </UiFormField>

                <UiFormField label="The longer explanation" :error="form.errors.description">
                    <template #default="field">
                        <UiTextarea :id="field.id" v-model="form.description" :rows="7" :invalid="field.invalid" />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="What the client gets">
                <UiFormField label="Deliverables" :error="form.errors.deliverables">
                    <template #default>
                        <UiRepeater v-model="form.deliverables" placeholder="Source code, in your repository, from the first commit" />
                    </template>
                </UiFormField>

                <UiFormField label="Engagement models" :error="form.errors.engagement_models">
                    <template #default>
                        <UiObjectRepeater
                            v-model="form.engagement_models"
                            title-key="name"
                            add-label="Add a model"
                            :fields="[
                                { key: 'name', label: 'Name', type: 'text', placeholder: 'Fixed scope' },
                                { key: 'body', label: 'What it means', type: 'textarea', rows: 2 },
                                { key: 'suits', label: 'Suits', type: 'text', placeholder: 'A first version with known boundaries' },
                            ]"
                        />
                    </template>
                </UiFormField>

                <UiFormField label="How it runs" :error="form.errors.process">
                    <template #default>
                        <UiObjectRepeater
                            v-model="form.process"
                            title-key="step"
                            add-label="Add a step"
                            :fields="[
                                { key: 'step', label: 'Step', type: 'text', placeholder: 'Understand' },
                                { key: 'body', label: 'What happens', type: 'textarea', rows: 2 },
                            ]"
                        />
                    </template>
                </UiFormField>

                <UiFormField label="Questions people ask" :error="form.errors.faqs">
                    <template #default>
                        <UiObjectRepeater
                            v-model="form.faqs"
                            title-key="q"
                            add-label="Add a question"
                            :fields="[
                                { key: 'q', label: 'Question', type: 'text' },
                                { key: 'a', label: 'Answer', type: 'textarea', rows: 3 },
                            ]"
                        />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="Visibility">
                <UiFormField label="Order" hint="Lower comes first." :error="form.errors.sort_order">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.sort_order" type="number" min="0" :invalid="field.invalid" />
                    </template>
                </UiFormField>

                <UiSwitch v-model="form.is_published" label="Live on the public site" />
            </FormSection>

            <div class="flex items-center justify-between gap-2">
                <UiButton v-if="isEdit" type="button" variant="ghost" size="sm" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                    Delete
                </UiButton>
                <span v-else />

                <span class="flex gap-2">
                    <UiButton href="/admin/services" variant="secondary">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">{{ isEdit ? 'Save changes' : 'Create' }}</UiButton>
                </span>
            </div>
        </form>
    </AppLayout>
</template>
