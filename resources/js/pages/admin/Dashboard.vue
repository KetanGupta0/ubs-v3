<script setup>
import DashboardStub from '@/components/Dashboard/DashboardStub.vue';

defineProps({ user: { type: Object, required: true } });

const upcoming = [
    { label: 'Leads inbox', phase: 'Phase 3', detail: 'Enquiries from the public site, assignable and convertible to clients.' },
    { label: 'Client accounts', phase: 'Phase 3', detail: 'Create an account and the credentials go out by email and SMS.' },
    { label: 'Catalogue', phase: 'Phase 3', detail: 'Everything the marketing site renders, edited from here.' },
    { label: 'Projects and billing', phase: 'Phase 4', detail: 'Delivery tracking, quotations, invoices and payments.' },
    { label: 'Courses and batches', phase: 'Phase 5', detail: 'Programmes, live classes, attendance and certificates.' },
    { label: 'Reports', phase: 'Phase 7', detail: 'Revenue, receivables, project health and batch performance.' },
];
</script>

<template>
    <DashboardStub
        title="Admin"
        intro="The control panel for both business lines. Everything below arrives in the phases noted."
        :user="user"
        :upcoming="upcoming"
    />
</template>
