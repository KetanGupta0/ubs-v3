<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiObjectRepeater from '@/components/UI/UiObjectRepeater.vue';

const props = defineProps({
    batch: { type: Object, default: null },
    courses: { type: Array, default: () => [] },
    trainers: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    defaultCourseId: { type: Number, default: null },
});

const isEdit = computed(() => Boolean(props.batch));

const form = useForm({
    course_id: props.batch?.course_id ?? props.defaultCourseId ?? props.courses[0]?.value ?? null,
    trainer_id: props.batch?.trainer_id ?? null,
    name: props.batch?.name ?? '',
    code: props.batch?.code ?? '',
    starts_on: props.batch?.starts_on ?? '',
    ends_on: props.batch?.ends_on ?? '',
    schedule: props.batch?.schedule ?? [],
    capacity: props.batch?.capacity ?? '',
    seats_taken: props.batch?.seats_taken ?? 0,
    status: props.batch?.status ?? 'upcoming',
    is_published: props.batch?.is_published ?? true,
});

function submit() {
    isEdit.value
        ? form.put(`/admin/batches/${props.batch.id}`, { preserveScroll: true })
        : form.post('/admin/batches');
}

function destroy() {
    if (confirm('Delete this batch?')) {
        router.delete(`/admin/batches/${props.batch.id}`);
    }
}
</script>

<template>
    <Head :title="isEdit ? batch.name : 'New batch'" />

    <AppLayout
        :title="isEdit ? batch.name : 'New batch'"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: 'Batches', href: '/admin/batches' },
            { label: isEdit ? 'Edit' : 'New' },
        ]"
    >
        <form class="mx-auto max-w-3xl space-y-5" @submit.prevent="submit">
            <PageHeader :title="isEdit ? batch.name : 'New batch'">
                <template #actions>
                    <UiButton href="/admin/batches" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="What and when">
                <UiFormField label="For" required :error="form.errors.course_id">
                    <template #default="field">
                        <UiCombobox :id="field.id" v-model="form.course_id" :options="courses" placeholder="Choose a course or internship" />
                    </template>
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Batch name" required :error="form.errors.name">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.name" placeholder="October 2026 batch" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>

                    <UiFormField label="Code" required hint="Unique, and used on paperwork." :error="form.errors.code">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.code" class="font-mono" placeholder="WEBDEV-OCT26" :invalid="field.invalid" required />
                        </template>
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Starts on" :error="form.errors.starts_on">
                        <template #default="field">
                            <UiDateInput :id="field.id" v-model="form.starts_on" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField label="Ends on" :error="form.errors.ends_on">
                        <template #default="field">
                            <UiDateInput :id="field.id" v-model="form.ends_on" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Weekly schedule" hint="Shown on the public page." :error="form.errors.schedule">
                    <template #default>
                        <UiObjectRepeater
                            v-model="form.schedule"
                            title-key="day"
                            add-label="Add a session"
                            :max="7"
                            :fields="[
                                { key: 'day', label: 'Day', type: 'text', placeholder: 'Mon' },
                                { key: 'from', label: 'From', type: 'text', placeholder: '19:30' },
                                { key: 'to', label: 'To', type: 'text', placeholder: '21:00' },
                            ]"
                        />
                    </template>
                </UiFormField>
            </FormSection>

            <FormSection title="Seats and staffing">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Capacity" hint="Leave empty for no cap." :error="form.errors.capacity">
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.capacity" type="number" min="1" :invalid="field.invalid" />
                        </template>
                    </UiFormField>

                    <UiFormField
                        label="Seats taken"
                        hint="This drives the seats left shown publicly."
                        :error="form.errors.seats_taken"
                    >
                        <template #default="field">
                            <UiInput :id="field.id" v-model="form.seats_taken" type="number" min="0" :invalid="field.invalid" />
                        </template>
                    </UiFormField>
                </div>

                <UiFormField label="Trainer" :error="form.errors.trainer_id">
                    <template #default="field">
                        <UiCombobox :id="field.id" v-model="form.trainer_id" :options="trainers" placeholder="Not assigned yet" />
                    </template>
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Status" required :error="form.errors.status">
                        <template #default="field">
                            <UiSelect :id="field.id" v-model="form.status" :options="statuses.map((s) => ({ value: s, label: s }))" />
                        </template>
                    </UiFormField>
                </div>

                <UiSwitch v-model="form.is_published" label="Visible on the public page" />
            </FormSection>

            <div class="flex items-center justify-between gap-2">
                <UiButton v-if="isEdit" type="button" variant="ghost" size="sm" @click="destroy">
                    <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                    Delete
                </UiButton>
                <span v-else />

                <span class="flex gap-2">
                    <UiButton href="/admin/batches" variant="secondary">Cancel</UiButton>
                    <UiButton type="submit" :loading="form.processing">{{ isEdit ? 'Save changes' : 'Create batch' }}</UiButton>
                </span>
            </div>
        </form>
    </AppLayout>
</template>
