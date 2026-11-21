<script setup>
/** The organisation's statement of account (CL14). */
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatementView from '@/components/Billing/StatementView.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ statement: { type: Object, required: true } });
</script>

<template>
    <Head title="Statement of account" />

    <AppLayout title="Statement of account" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Payments', href: '/client/payments' }, { label: 'Statement' }]">
        <div class="space-y-5">
            <PageHeader title="Statement of account" description="Invoices, and the payments, TDS and credit notes that settled them, with the balance after each. It starts at the beginning of the financial year.">
                <template #actions>
                    <UiButton href="/client/payments" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Payments
                    </UiButton>
                </template>
            </PageHeader>
            <StatementView :statement="statement" base="/client/statement" />
        </div>
    </AppLayout>
</template>
