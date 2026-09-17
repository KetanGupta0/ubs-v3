<script setup>
/**
 * A lesson that is not open yet.
 *
 * A page rather than a refusal, because the student is allowed to know the
 * lesson exists and what would open it. "Access denied" answers nothing.
 */
import { Head } from '@inertiajs/vue3';
import { Lock, ArrowLeft, CreditCard, Calendar, ClipboardList, Circle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    course: { type: Object, required: true },
    lesson: { type: Object, required: true },
    lock: { type: Object, required: true },
    paymentId: { type: Number, default: null },
});

const icons = { payment: CreditCard, schedule: Calendar, prerequisite: Circle, quiz: ClipboardList };
</script>

<template>
    <Head :title="lesson.title" />

    <AppLayout
        :title="lesson.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: course.title, href: `/student/courses/${course.id}` },
            { label: 'Locked' },
        ]"
    >
        <div class="mx-auto max-w-xl">
            <UiCard>
                <div class="text-center">
                    <span
                        class="mx-auto mb-4 inline-flex h-14 w-14 items-center justify-center rounded-2xl"
                        style="background: var(--surface-sunken); color: var(--text-muted)"
                    >
                        <component :is="icons[lock.kind] ?? Lock" class="h-6 w-6" />
                    </span>

                    <h1 class="text-lg font-semibold">{{ lesson.title }}</h1>
                    <p v-if="lesson.summary" class="mt-1 text-sm" style="color: var(--text-muted)">
                        {{ lesson.summary }}
                    </p>

                    <p class="mt-5 rounded-[var(--radius-field)] px-4 py-3 text-sm"
                       style="background: var(--surface-sunken)">
                        {{ lock.reason }}
                    </p>

                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <UiButton :href="`/student/courses/${course.id}`" variant="ghost" size="sm">
                            <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                            Back to the course
                        </UiButton>

                        <UiButton v-if="lock.kind === 'payment' && paymentId" :href="`/student/payments/${paymentId}`" size="sm">
                            <template #leading><CreditCard class="h-3.5 w-3.5" /></template>
                            Pay the fee
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
