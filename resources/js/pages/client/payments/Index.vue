<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { CreditCard, ArrowRight, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    pending: { type: Array, default: () => [] },
    recent: { type: Array, default: () => [] },
    summary: { type: Object, default: () => ({}) },
});
</script>

<template>
    <Head title="Payments" />

    <AppLayout title="Payments" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Payments' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                title="Payments"
                description="What is due, and what has already been settled. Every invoice is downloadable before you pay it."
            >
                <template #actions>
                    <UiButton href="/client/transactions" variant="ghost" size="sm">
                        Full history
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-3">
                <StatTile label="Billed to date" :value="summary.billed ?? '₹0.00'" />
                <StatTile label="Paid" :value="summary.paid ?? '₹0.00'" tone="success" />
                <StatTile
                    label="Outstanding"
                    :value="summary.due ?? '₹0.00'"
                    :tone="summary.due && summary.due !== '₹0.00' ? 'warning' : 'neutral'"
                />
            </div>

            <!-- --------------------------------------------------- pending -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Due now
                </h2>

                <div
                    v-if="!pending.length"
                    class="flex items-center gap-3 rounded-[var(--radius-card)] border px-4 py-3.5"
                    style="border-color: var(--border-subtle); background: var(--surface)"
                >
                    <CheckCircle2 class="h-5 w-5" style="color: var(--color-signal-500)" />
                    <p class="text-sm">Nothing outstanding. Thank you.</p>
                </div>

                <ul v-else class="space-y-3">
                    <li v-for="payment in pending" :key="payment.id">
                        <UiCard :padding="'p-4'">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                        {{ payment.title }}
                                        <UiBadge v-if="payment.overdue" tone="danger" size="sm">
                                            <AlertTriangle class="mr-1 h-3 w-3" />overdue
                                        </UiBadge>
                                    </p>
                                    <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                        {{ payment.reference }}
                                        <span v-if="payment.dueOn"> · due {{ payment.dueOn }}</span>
                                    </p>
                                </div>

                                <div class="flex items-center gap-3">
                                    <span class="text-lg font-semibold tnum">{{ payment.total }}</span>
                                    <UiButton :href="`/client/payments/${payment.id}`" size="sm">
                                        <template #leading><CreditCard class="h-3.5 w-3.5" /></template>
                                        Pay
                                    </UiButton>
                                </div>
                            </div>
                        </UiCard>
                    </li>
                </ul>
            </section>

            <!-- ---------------------------------------------------- recent -->
            <section v-if="recent.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Recently settled
                </h2>

                <ul class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]"
                    style="border-color: var(--border-subtle)">
                    <li v-for="payment in recent" :key="payment.id">
                        <Link :href="`/client/payments/${payment.id}`" class="flex items-center gap-3 p-4 transition hover:bg-[var(--surface-sunken)]">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ payment.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ payment.paidAt ? `Paid ${payment.paidAt}` : payment.statusLabel }}
                                </span>
                            </span>
                            <span class="tnum text-sm">{{ payment.total }}</span>
                            <UiBadge :tone="payment.status === 'paid' ? 'success' : 'neutral'" size="sm">
                                {{ payment.statusLabel }}
                            </UiBadge>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
