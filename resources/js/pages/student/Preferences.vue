<script setup>
/**
 * Reminders a student gets, and what the platform records about their
 * studying. Both said plainly, in one place.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { BellRing, Eye } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';

const props = defineProps({
    reminders: { type: Array, default: () => [] },
});

const state = useForm(Object.fromEntries(props.reminders.map((reminder) => [reminder.kind, reminder.on])));

function save() {
    state
        .transform((data) => ({ on: Object.keys(data).filter((kind) => data[kind]) }))
        .put('/student/preferences', { preserveScroll: true, onSuccess: () => state.defaults() });
}

const recorded = [
    'When you sign in.',
    'Which lessons you open, and roughly how long you spend on each, counted only while the page is in front of you and shown as a range such as "15 to 30 minutes", never to the second.',
    'When you join a live class from here, and the attendance your trainer marks.',
    'When you hand in an assignment or sit a quiz, and the result.',
    'How many messages you post in your batch group. What they say is not part of it.',
];
</script>

<template>
    <Head title="Reminders" />

    <AppLayout title="Reminders" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Reminders' }]">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader title="Reminders and your record" description="What we remind you about, and what we keep about your studying." />

            <form @submit.prevent="save">
                <FormSection title="Reminders" description="Each comes once, here in the app and by email. Switch off any you do not want.">
                    <div class="space-y-4">
                        <UiSwitch
                            v-for="reminder in reminders"
                            :key="reminder.kind"
                            v-model="state[reminder.kind]"
                            :label="reminder.label"
                            :description="reminder.description"
                        />
                    </div>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="state.processing" :disabled="!state.isDirty">
                            <template #leading><BellRing class="h-3.5 w-3.5" /></template>
                            Save
                        </UiButton>
                    </div>
                </FormSection>
            </form>

            <FormSection title="What we record" description="So you and your trainer can see how your studying is going.">
                <ul class="list-disc space-y-1.5 pl-5 text-sm">
                    <li v-for="item in recorded" :key="item">{{ item }}</li>
                </ul>
                <p class="flex items-start gap-2 text-sm" style="color: var(--text-muted)">
                    <Eye class="mt-0.5 h-4 w-4 shrink-0" />
                    You, your trainer and the team can see it. It is never used to grade you or to warn you without a person looking at it first. Your notes stay private to you.
                </p>
            </FormSection>
        </div>
    </AppLayout>
</template>
