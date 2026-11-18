<script setup>
/**
 * Who in the organisation did what (CL20), for its owners: actions,
 * payments and recent sign ins, so something done in a member's name that
 * nobody remembers doing is noticed.
 */
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';

defineProps({
    table: { type: Object, required: true },
    payments: { type: Array, default: () => [] },
    signIns: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Who did what" />

    <AppLayout title="Who did what" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'People', href: '/client/people' }, { label: 'Activity' }]">
        <div class="mx-auto max-w-7xl space-y-5">
            <PageHeader title="Who did what" description="What people in your organisation have done here, with where they were. If something was done in somebody's name that they do not remember, tell us at once.">
                <template #actions>
                    <UiButton href="/client/people" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        People
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by what it was on…" empty-title="Nothing yet" empty-description="Signing, accepting, replies and payments show here as people do them." />

            <div class="grid gap-5 lg:grid-cols-2">
                <UiCard>
                    <h2 class="text-base font-semibold">Payments</h2>
                    <p v-if="!payments.length" class="mt-2 text-sm" style="color: var(--text-muted)">None yet.</p>
                    <ul v-else class="mt-3 divide-y text-sm">
                        <li v-for="payment in payments" :key="payment.id" class="flex flex-wrap items-center gap-2 py-2">
                            <span class="font-medium tnum">{{ payment.amount }}</span>
                            <span style="color: var(--text-muted)">by {{ payment.who }}<template v-if="payment.invoice"> · {{ payment.invoice }}</template> · {{ payment.at }}</span>
                        </li>
                    </ul>
                </UiCard>
                <UiCard>
                    <h2 class="text-base font-semibold">Recent sign ins</h2>
                    <p v-if="!signIns.length" class="mt-2 text-sm" style="color: var(--text-muted)">None yet.</p>
                    <ul v-else class="mt-3 divide-y text-sm">
                        <li v-for="signIn in signIns" :key="signIn.id" class="flex flex-wrap items-center gap-2 py-2">
                            <span class="font-medium">{{ signIn.who }}</span>
                            <span style="color: var(--text-muted)">{{ signIn.at }}<template v-if="signIn.method"> · {{ signIn.method }}</template><template v-if="signIn.ip"> · {{ signIn.ip }}</template></span>
                        </li>
                    </ul>
                </UiCard>
            </div>
        </div>
    </AppLayout>
</template>
