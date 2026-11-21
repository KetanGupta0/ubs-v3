<script setup>
/** Scheduling a meeting with a client, or moving one (CL11). */
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    meeting: { type: Object, default: null },
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
    preselectedClient: { type: Number, default: null },
    preselectedProject: { type: Number, default: null },
});

const form = useForm({
    client_id: props.meeting?.client_id ?? props.preselectedClient,
    project_id: props.meeting?.project_id ?? props.preselectedProject ?? '',
    title: props.meeting?.title ?? '',
    agenda: props.meeting?.agenda ?? '',
    starts_at: props.meeting?.starts_at ?? '',
    duration_minutes: props.meeting?.duration_minutes ?? 30,
    link: props.meeting?.link ?? '',
    location: props.meeting?.location ?? '',
});

const projectOptions = computed(() => [{ value: '', label: 'Not about one project' }, ...props.projects.filter((project) => project.clientId === form.client_id)]);
watch(() => form.client_id, () => {
    if (!projectOptions.value.some((option) => option.value === form.project_id)) form.project_id = '';
});

const durations = [15, 30, 45, 60, 90, 120].map((minutes) => ({ value: minutes, label: minutes < 60 ? `${minutes} minutes` : `${minutes / 60} hour${minutes > 60 ? 's' : ''}` }));

function save() {
    const data = (values) => ({ ...values, project_id: values.project_id || null });

    props.meeting
        ? form.transform(data).put(`/admin/meetings/${props.meeting.id}`)
        : form.transform(data).post('/admin/meetings');
}
</script>

<template>
    <Head :title="meeting ? 'Change the meeting' : 'Schedule a meeting'" />

    <AppLayout
        :title="meeting ? 'Change the meeting' : 'Schedule a meeting'"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Meetings', href: '/admin/meetings' }, { label: meeting ? meeting.title : 'New' }]"
    >
        <div class="space-y-5">
            <PageHeader
                :title="meeting ? 'Change the meeting' : 'Schedule a meeting'"
                description="Times are India time. The client's people are told by email and in their account; if a meeting already happened, record it and write the minutes."
            >
                <template #actions>
                    <UiButton :href="meeting ? `/admin/meetings/${meeting.id}` : '/admin/meetings'" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <form class="space-y-4" @submit.prevent="save">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Client" required :error="form.errors.client_id">
                            <UiCombobox v-model="form.client_id" :options="clients" placeholder="Choose the organisation" />
                        </UiFormField>
                        <UiFormField label="Project" :error="form.errors.project_id">
                            <UiSelect v-model="form.project_id" :options="projectOptions" :disabled="!form.client_id" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Title" required :error="form.errors.title">
                        <UiInput v-model="form.title" maxlength="160" placeholder="Kick-off call" />
                    </UiFormField>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Starts" required :error="form.errors.starts_at">
                            <UiInput v-model="form.starts_at" type="datetime-local" />
                        </UiFormField>
                        <UiFormField label="Length" required :error="form.errors.duration_minutes">
                            <UiSelect v-model="form.duration_minutes" :options="durations" />
                        </UiFormField>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Meeting link" hint="An https:// address, such as a video call." :error="form.errors.link">
                            <UiInput v-model="form.link" type="url" placeholder="https://" />
                        </UiFormField>
                        <UiFormField label="Or a place" :error="form.errors.location">
                            <UiInput v-model="form.location" maxlength="160" placeholder="Their office, Indore" />
                        </UiFormField>
                    </div>
                    <UiFormField label="Agenda" hint="Optional. What the meeting is for." :error="form.errors.agenda">
                        <UiTextarea v-model="form.agenda" :rows="4" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing">
                            <template #leading><CalendarClock class="h-4 w-4" /></template>
                            {{ meeting ? 'Save' : 'Schedule' }}
                        </UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
