<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Trash2, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';

const props = defineProps({
    college: { type: Object, default: null },
});

const isEdit = computed(() => Boolean(props.college));

const form = useForm({
    name: props.college?.name ?? '',
    slug: props.college?.slug ?? '',
    short_name: props.college?.short_name ?? '',
    city: props.college?.city ?? '',
    state: props.college?.state ?? '',
    university: props.college?.university ?? '',
    coordinator_name: props.college?.coordinator_name ?? '',
    coordinator_email: props.college?.coordinator_email ?? '',
    coordinator_mobile: props.college?.coordinator_mobile ?? '',
    mou_signed_on: props.college?.mou_signed_on ?? '',
    mou_expires_on: props.college?.mou_expires_on ?? '',
    notes: props.college?.notes ?? '',
    is_active: props.college?.is_active ?? true,
});

const title = computed(() => (isEdit.value ? props.college.name : 'New college'));

function submit() {
    isEdit.value
        ? form.put(`/admin/colleges/${props.college.slug}`, { preserveScroll: true })
        : form.post('/admin/colleges');
}

function destroy() {
    if (confirm(`Remove ${props.college.name}?`)) {
        router.delete(`/admin/colleges/${props.college.slug}`);
    }
}
</script>

<template>
    <Head :title="title" />

    <AppLayout
        :title="title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Colleges', href: '/admin/colleges' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="title">
                <template #actions>
                    <UiButton href="/admin/colleges" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <p
                v-if="isEdit && college.studentCount"
                class="flex items-center gap-2 rounded-[var(--radius-card)] border px-4 py-3 text-sm"
                style="border-color: var(--border-subtle); color: var(--text-muted)"
            >
                <Users class="h-4 w-4" />
                {{ college.studentCount }} student{{ college.studentCount === 1 ? ' is' : 's are' }} linked to this
                college.
            </p>

            <FormSection title="The institution">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="form.errors.name" class="sm:col-span-2">
                        <UiInput v-model="form.name" placeholder="Government Engineering College, Bhopal" />
                    </UiFormField>

                    <UiFormField label="Short name" :error="form.errors.short_name" hint="What people actually call it.">
                        <UiInput v-model="form.short_name" placeholder="GEC Bhopal" />
                    </UiFormField>

                    <UiFormField
                        label="Address in links"
                        :error="form.errors.slug"
                        hint="Left blank, this comes from the name."
                    >
                        <UiInput v-model="form.slug" placeholder="gec-bhopal" />
                    </UiFormField>

                    <UiFormField label="City" :error="form.errors.city">
                        <UiInput v-model="form.city" />
                    </UiFormField>

                    <UiFormField label="State" :error="form.errors.state">
                        <UiInput v-model="form.state" />
                    </UiFormField>

                    <UiFormField label="University" :error="form.errors.university" class="sm:col-span-2">
                        <UiInput v-model="form.university" placeholder="Rajiv Gandhi Proudyogiki Vishwavidyalaya" />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                title="Coordinator"
                description="The one person at the college who answers when a batch needs sorting out."
            >
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Name" :error="form.errors.coordinator_name">
                        <UiInput v-model="form.coordinator_name" />
                    </UiFormField>

                    <UiFormField label="Email" :error="form.errors.coordinator_email">
                        <UiInput v-model="form.coordinator_email" type="email" />
                    </UiFormField>

                    <UiFormField
                        label="Mobile"
                        :error="form.errors.coordinator_mobile"
                        hint="With country code, or +91 is assumed."
                    >
                        <UiInput v-model="form.coordinator_mobile" type="tel" placeholder="+91 98765 43210" />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection
                title="Memorandum"
                description="Dates so the tie-up gets renewed before it lapses rather than after."
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Signed on" :error="form.errors.mou_signed_on">
                        <UiDateInput v-model="form.mou_signed_on" />
                    </UiFormField>

                    <UiFormField label="Expires on" :error="form.errors.mou_expires_on">
                        <UiDateInput v-model="form.mou_expires_on" />
                    </UiFormField>
                </div>

                <UiFormField label="Notes" :error="form.errors.notes" hint="Anything worth remembering before the next conversation.">
                    <UiTextarea v-model="form.notes" :rows="4" />
                </UiFormField>

                <UiSwitch
                    v-model="form.is_active"
                    label="Active tie-up"
                    description="Inactive colleges stay on record but are out of the way."
                />
            </FormSection>

            <div class="flex flex-wrap items-center justify-between gap-3 pb-safe">
                <UiButton
                    v-if="isEdit"
                    variant="ghost"
                    size="sm"
                    type="button"
                    @click="destroy"
                >
                    <template #leading><Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" /></template>
                    Remove
                </UiButton>
                <span v-else />

                <UiButton type="submit" :loading="form.processing">
                    {{ isEdit ? 'Save college' : 'Add college' }}
                </UiButton>
            </div>
        </form>
    </AppLayout>
</template>
