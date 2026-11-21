<script setup>
/** Recording a change a client asked for some other way, such as on a call (CL12). */
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    projects: { type: Array, default: () => [] },
    preselectedProject: { type: Number, default: null },
});

const form = useForm({ project_id: props.preselectedProject, title: '', description: '' });
</script>

<template>
    <Head title="Record a change" />

    <AppLayout title="Record a change" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Change requests', href: '/admin/change-requests' }, { label: 'New' }]">
        <div class="space-y-5">
            <PageHeader title="Record a change" description="What the client asked for, in their words where you can. You estimate it on the next screen.">
                <template #actions>
                    <UiButton href="/admin/change-requests" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <form class="space-y-4" @submit.prevent="form.post('/admin/change-requests')">
                    <UiFormField label="Project" required :error="form.errors.project_id">
                        <UiCombobox v-model="form.project_id" :options="projects" placeholder="Choose the project" />
                    </UiFormField>
                    <UiFormField label="The change" required :error="form.errors.title">
                        <UiInput v-model="form.title" maxlength="160" placeholder="Add returns to the driver app" />
                    </UiFormField>
                    <UiFormField label="What they asked for" required :error="form.errors.description">
                        <UiTextarea v-model="form.description" :rows="6" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing">Record it</UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
