<script setup>
/**
 * Courses a signed in student can join.
 *
 * Includes the offerings marked LMS only, because a student already inside is
 * exactly who those exist for. The public site still never lists them.
 */
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { BookOpen, Clock, Users, Lock, Check } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    courses: { type: Array, default: () => [] },
});

const joining = ref(null);

const form = useForm({ course_id: null, batch_id: null });

const open = (course) => {
    joining.value = course;
    form.clearErrors();
    form.course_id = course.id;
    form.batch_id = course.batches.find((batch) => !batch.full)?.id ?? null;
};

const submit = () => {
    form.post('/student/enrol', {
        onSuccess: () => { joining.value = null; },
    });
};

const scheduleLine = (batch) =>
    (batch.schedule ?? []).map((slot) => `${slot.day} ${slot.from}–${slot.to}`).join(', ');
</script>

<template>
    <Head title="Course catalogue" />

    <AppLayout title="Catalogue" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Catalogue' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                title="Join another course"
                description="Everything open to you, including programmes that run inside the portal only."
            />

            <UiEmptyState
                v-if="!courses.length"
                :icon="BookOpen"
                title="Nothing on offer right now"
                description="New courses and internships appear here as they open."
            />

            <div v-else class="grid gap-4 sm:grid-cols-2">
                <UiCard v-for="course in courses" :key="course.id" class="flex flex-col">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h2 class="text-sm font-semibold">{{ course.title }}</h2>
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <UiBadge v-if="course.type === 'internship'" tone="accent" size="sm">internship</UiBadge>
                            <UiBadge v-if="course.lmsOnly" size="sm">
                                <Lock class="mr-1 h-3 w-3" />portal only
                            </UiBadge>
                        </div>
                    </div>

                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ course.tagline }}</p>
                    <p class="mt-3 text-sm" style="color: var(--text-muted)">{{ course.summary }}</p>

                    <ul class="mt-4 space-y-1.5 text-xs" style="color: var(--text-muted)">
                        <li class="flex items-center gap-1.5">
                            <Clock class="h-3.5 w-3.5" />
                            {{ course.duration }} · {{ course.level }} · {{ course.mode }}
                        </li>
                        <li v-if="course.batches.length" class="flex items-center gap-1.5">
                            <Users class="h-3.5 w-3.5" />
                            {{ course.batches.length }} batch{{ course.batches.length === 1 ? '' : 'es' }} open
                        </li>
                    </ul>

                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 pt-1">
                        <span class="text-sm font-semibold">{{ course.price }}</span>

                        <UiButton v-if="course.enrolled" :href="`/student/courses/${course.id}`" variant="secondary" size="sm">
                            <template #leading><Check class="h-3.5 w-3.5" /></template>
                            You are on this
                        </UiButton>
                        <UiButton v-else size="sm" @click="open(course)">Join</UiButton>
                    </div>
                </UiCard>
            </div>
        </div>

        <!-- --------------------------------------------------- pick a batch -->
        <UiModal :open="joining !== null" :title="joining?.title ?? ''" @close="joining = null">
            <form class="space-y-4" @submit.prevent="submit">
                <p v-if="!joining?.free" class="text-sm" style="color: var(--text-muted)">
                    The fee is {{ joining?.price }}. You are enrolled straight away and can see the syllabus and
                    schedule while it is settled; only the lessons marked as needing payment stay closed.
                </p>

                <UiFormField
                    v-if="joining?.batches.length"
                    label="Batch"
                    :error="form.errors.batch_id"
                    hint="Pick the timing that fits. You can ask to move later."
                >
                    <UiSelect v-model="form.batch_id">
                        <option v-for="batch in joining.batches" :key="batch.id" :value="batch.id" :disabled="batch.full">
                            {{ batch.name }}
                            <template v-if="batch.startsOn"> — starts {{ batch.startsOn }}</template>
                            <template v-if="scheduleLine(batch)"> — {{ scheduleLine(batch) }}</template>
                            <template v-if="batch.full"> (full)</template>
                        </option>
                    </UiSelect>
                </UiFormField>

                <p v-else class="text-sm" style="color: var(--text-muted)">
                    No batch is scheduled yet. You will be enrolled and told as soon as dates are set.
                </p>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="joining = null">Cancel</UiButton>
                <UiButton :loading="form.processing" @click="submit">
                    {{ joining?.free ? 'Join' : 'Join and raise the fee' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
