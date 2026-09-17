<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, ArrowRight, AlertTriangle, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    courses: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Your courses" />

    <AppLayout title="My courses" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Courses' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader title="My courses" description="Everything you are enrolled on, and how far through you are.">
                <template #actions>
                    <UiButton href="/student/catalogue" variant="secondary" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Join another
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!courses.length"
                :icon="BookOpen"
                title="Nothing here yet"
                description="Once you join a course it appears here with everything in it."
            >
                <template #action>
                    <UiButton href="/student/catalogue" size="sm">Browse what is running</UiButton>
                </template>
            </UiEmptyState>

            <UiCard v-for="course in courses" :key="course.id">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2">
                            <Link :href="`/student/courses/${course.id}`" class="text-base font-semibold hover:underline">
                                {{ course.title }}
                            </Link>
                            <UiBadge v-if="course.type === 'internship'" tone="accent" size="sm">internship</UiBadge>
                            <UiBadge :tone="course.status === 'completed' ? 'success' : 'neutral'" size="sm" dot>
                                {{ course.statusLabel }}
                            </UiBadge>
                        </p>

                        <p class="mt-1 text-sm" style="color: var(--text-muted)">{{ course.tagline }}</p>

                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 text-xs" style="color: var(--text-muted)">
                            <span v-if="course.batch">{{ course.batch }}</span>
                            <span v-if="course.startsOn">{{ course.startsOn }}<span v-if="course.endsOn"> to {{ course.endsOn }}</span></span>
                            <span>{{ course.lessons }} {{ course.lessons === 1 ? 'lesson' : 'lessons' }}</span>
                        </p>
                    </div>

                    <UiButton :href="`/student/courses/${course.id}`" size="sm">
                        {{ course.progress > 0 ? 'Continue' : 'Start' }}
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </div>

                <div class="mt-4">
                    <UiProgress :value="course.progress" label="Progress" />
                </div>

                <p
                    v-if="!course.hasPaid"
                    class="mt-4 flex flex-wrap items-center gap-2 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                    style="background: var(--surface-sunken)"
                >
                    <AlertTriangle class="h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                    <span style="color: var(--text-muted)">
                        {{ course.feeDue }} outstanding. Some lessons stay sealed until it is paid.
                    </span>
                    <UiButton
                        v-if="course.paymentId"
                        :href="`/student/payments/${course.paymentId}`"
                        variant="ghost"
                        size="xs"
                    >
                        Pay now
                    </UiButton>
                </p>
            </UiCard>
        </div>
    </AppLayout>
</template>
