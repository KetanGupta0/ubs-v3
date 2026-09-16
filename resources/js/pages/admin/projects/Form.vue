<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';

const props = defineProps({
    project: { type: Object, default: null },
    clients: { type: Array, default: () => [] },
    managers: { type: Array, default: () => [] },
    solutions: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
});

const isEdit = computed(() => Boolean(props.project));

const form = useForm({
    client_id: props.project?.client_id ?? null,
    name: props.project?.name ?? '',
    solution_id: props.project?.solution_id ?? null,
    summary: props.project?.summary ?? '',
    scope: props.project?.scope ?? '',
    status: props.project?.status ?? 'planning',
    phase: props.project?.phase ?? '',
    progress_percent: props.project?.progress_percent ?? 0,
    start_date: props.project?.start_date ?? '',
    target_date: props.project?.target_date ?? '',
    delivered_on: props.project?.delivered_on ?? '',
    budget: props.project?.budget ?? null,
    manager_id: props.project?.manager_id ?? null,
    team: props.project?.team ?? [],
    repository_url: props.project?.repository_url ?? '',
    staging_url: props.project?.staging_url ?? '',
    production_url: props.project?.production_url ?? '',
});

const title = computed(() => (isEdit.value ? props.project.name : 'New project'));

function submit() {
    isEdit.value
        ? form.put(`/admin/projects/${props.project.id}`, { preserveScroll: true })
        : form.post('/admin/projects');
}

function destroy() {
    if (confirm(`Remove ${props.project.name}? Its milestones and updates go with it.`)) {
        router.delete(`/admin/projects/${props.project.id}`);
    }
}
</script>

<template>
    <Head :title="title" />

    <AppLayout
        :title="title"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Projects', href: '/admin/projects' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="title" :description="isEdit ? project.code : null">
                <template #actions>
                    <UiButton :href="isEdit ? `/admin/projects/${project.id}` : '/admin/projects'" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="The work">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Client" required :error="form.errors.client_id" class="sm:col-span-2">
                        <UiCombobox v-model="form.client_id" :options="clients" placeholder="Search clients…" />
                    </UiFormField>

                    <UiFormField label="Project name" required :error="form.errors.name" class="sm:col-span-2">
                        <UiInput v-model="form.name" placeholder="Warehouse management system" />
                    </UiFormField>

                    <UiFormField label="Built on" :error="form.errors.solution_id" hint="If it starts from one of our products.">
                        <UiSelect
                            v-model="form.solution_id"
                            :options="[{ value: '', label: 'Built from scratch' }, ...solutions]"
                        />
                    </UiFormField>

                    <UiFormField label="Delivery lead" :error="form.errors.manager_id">
                        <UiSelect
                            v-model="form.manager_id"
                            :options="[{ value: '', label: 'Unassigned' }, ...managers]"
                        />
                    </UiFormField>

                    <UiFormField label="One line summary" :error="form.errors.summary" class="sm:col-span-2">
                        <UiInput v-model="form.summary" />
                    </UiFormField>

                    <UiFormField
                        label="Scope"
                        :error="form.errors.scope"
                        hint="What is in, and what is explicitly out. The client does not see this."
                        class="sm:col-span-2"
                    >
                        <UiTextarea v-model="form.scope" :rows="6" />
                    </UiFormField>
                </div>
            </FormSection>

            <FormSection title="Where it stands">
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Status" required :error="form.errors.status">
                        <UiSelect v-model="form.status" :options="statuses" />
                    </UiFormField>

                    <UiFormField label="Phase" :error="form.errors.phase" hint="Shown to the client.">
                        <UiInput v-model="form.phase" placeholder="Build" />
                    </UiFormField>

                    <UiFormField label="Progress (%)" required :error="form.errors.progress_percent">
                        <UiInput v-model="form.progress_percent" type="number" min="0" max="100" />
                    </UiFormField>

                    <UiFormField label="Started" :error="form.errors.start_date">
                        <UiDateInput v-model="form.start_date" />
                    </UiFormField>

                    <UiFormField label="Target" :error="form.errors.target_date">
                        <UiDateInput v-model="form.target_date" />
                    </UiFormField>

                    <UiFormField label="Delivered" :error="form.errors.delivered_on">
                        <UiDateInput v-model="form.delivered_on" />
                    </UiFormField>
                </div>

                <UiFormField label="Agreed value (₹)" :error="form.errors.budget" hint="Shown to the client on the project page.">
                    <UiInput v-model="form.budget" type="number" step="0.01" min="0" />
                </UiFormField>
            </FormSection>

            <FormSection title="Team and links">
                <UiFormField label="Team" :error="form.errors.team" hint="Names the client sees on their project page.">
                    <UiRepeater v-model="form.team" placeholder="Add a name" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Repository" :error="form.errors.repository_url" hint="Internal only.">
                        <UiInput v-model="form.repository_url" type="url" />
                    </UiFormField>

                    <UiFormField label="Staging" :error="form.errors.staging_url" hint="The client can open this.">
                        <UiInput v-model="form.staging_url" type="url" />
                    </UiFormField>

                    <UiFormField label="Production" :error="form.errors.production_url">
                        <UiInput v-model="form.production_url" type="url" />
                    </UiFormField>
                </div>
            </FormSection>

            <div class="flex flex-wrap items-center justify-between gap-3 pb-safe">
                <UiButton v-if="isEdit" variant="ghost" size="sm" type="button" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" /></template>
                    Remove
                </UiButton>
                <span v-else />

                <UiButton type="submit" :loading="form.processing">
                    {{ isEdit ? 'Save project' : 'Create project' }}
                </UiButton>
            </div>
        </form>
    </AppLayout>
</template>
