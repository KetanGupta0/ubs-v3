<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({ categories: { type: Array, default: () => [] } });

const form = useForm({ subject: '', category: props.categories[0]?.value ?? 'other', body: '' });

const submit = () => form.post('/student/support');
</script>

<template>
    <Head title="New ticket" />

    <AppLayout
        title="New ticket"
        :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Support', href: '/student/support' }, { label: 'New ticket' }]"
    >
        <form class="mx-auto max-w-2xl space-y-5" @submit.prevent="submit">
            <PageHeader title="New ticket" description="The more specific the first message, the faster the answer.">
                <template #actions>
                    <UiButton href="/student/support" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="What do you need help with?">
                <UiFormField label="About" required :error="form.errors.category">
                    <UiSelect v-model="form.category" :options="categories" />
                </UiFormField>
                <UiFormField label="Subject" required :error="form.errors.subject">
                    <UiInput v-model="form.subject" placeholder="Cannot open week 3 of my course" />
                </UiFormField>
                <UiFormField label="What is happening" required hint="What you did, what you expected, and what happened instead." :error="form.errors.body">
                    <UiTextarea v-model="form.body" :rows="7" />
                </UiFormField>
            </FormSection>

            <div class="flex justify-end pb-safe">
                <UiButton type="submit" :loading="form.processing">Send</UiButton>
            </div>
        </form>
    </AppLayout>
</template>
