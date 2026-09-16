<script setup>
import DashboardStub from '@/components/Dashboard/DashboardStub.vue';

defineProps({ user: { type: Object, required: true } });

const upcoming = [
    { label: 'Your courses', phase: 'Phase 5', detail: 'Modules and lessons, unlocking as you progress.' },
    { label: 'Live classes', phase: 'Phase 5', detail: 'Google Meet links that open when class starts.' },
    { label: 'Attendance', phase: 'Phase 5', detail: 'Your record per session, and a warning if it slips.' },
    { label: 'Quizzes and assignments', phase: 'Phase 5', detail: 'Timed attempts, submissions and feedback.' },
    { label: 'Certificates', phase: 'Phase 5', detail: 'Issued on completion, with a public verification link.' },
    { label: 'Batch chat', phase: 'Phase 6', detail: 'One room per batch, for you and your trainer.' },
];
</script>

<template>
    <DashboardStub
        title="Dashboard"
        intro="Your classes, materials and results will live here."
        :user="user"
        :upcoming="upcoming"
    />
</template>
