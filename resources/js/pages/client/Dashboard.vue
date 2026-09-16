<script setup>
import DashboardStub from '@/components/Dashboard/DashboardStub.vue';

defineProps({ user: { type: Object, required: true } });

const upcoming = [
    { label: 'Your projects', phase: 'Phase 4', detail: 'Milestones, progress and a live development tracker.' },
    { label: 'Proposals and quotations', phase: 'Phase 4', detail: 'Read, accept or decline, with the decision recorded.' },
    { label: 'Documents', phase: 'Phase 4', detail: 'Everything shared on your engagement, versioned.' },
    { label: 'Payments', phase: 'Phase 4', detail: 'Pay online, with invoices and receipts you can print.' },
    { label: 'Support and maintenance', phase: 'Phase 4', detail: 'Raise a ticket and watch the clock against the agreed response time.' },
    { label: 'Chat with the team', phase: 'Phase 6', detail: 'Messages, images and voice notes, per project.' },
];
</script>

<template>
    <DashboardStub
        title="Overview"
        intro="Your projects, documents and payments will live here."
        :user="user"
        :upcoming="upcoming"
    />
</template>
