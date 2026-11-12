<script setup>
import { Head } from '@inertiajs/vue3';
import { TrendingUp } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiButton from '@/components/UI/UiButton.vue';
import PerformanceCard from '@/components/Student/PerformanceCard.vue';

defineProps({
    courses: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="My progress" />

    <AppLayout title="My progress" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'My progress' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader title="My progress" description="How each of your courses is going, from what has been recorded. Your trainer sees the same." />

            <UiEmptyState v-if="!courses.length" :icon="TrendingUp" title="Nothing to show yet" description="Join a course and this fills in as you go.">
                <template #action>
                    <UiButton href="/student/catalogue" size="sm">Browse courses</UiButton>
                </template>
            </UiEmptyState>

            <PerformanceCard v-for="course in courses" :key="course.courseId" :performance="course" />
        </div>
    </AppLayout>
</template>
