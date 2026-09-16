<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, ShieldCheck, LifeBuoy, AlertTriangle, ArrowRight, Clock } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    contracts: { type: Array, default: () => [] },
    tickets: { type: Array, default: () => [] },
    canRaise: { type: Boolean, default: false },
});

const priorityTones = { urgent: 'danger', high: 'warning', normal: 'neutral', low: 'neutral' };
</script>

<template>
    <Head title="Support" />

    <AppLayout title="Support" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Support' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                title="Support"
                description="Your maintenance cover, and everything you have raised against it."
            >
                <template #actions>
                    <UiButton v-if="canRaise" href="/client/tickets/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Raise a ticket
                    </UiButton>
                </template>
            </PageHeader>

            <!-- ------------------------------------------------- contracts -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Maintenance cover
                </h2>

                <UiEmptyState
                    v-if="!contracts.length"
                    :icon="ShieldCheck"
                    title="No maintenance contract"
                    description="Ask us about an annual maintenance contract and it will appear here."
                />

                <div v-else class="grid gap-4 sm:grid-cols-2">
                    <UiCard v-for="contract in contracts" :key="contract.id">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p class="text-base font-semibold">{{ contract.plan }}</p>
                                <p class="text-xs" style="color: var(--text-muted)">
                                    {{ contract.reference }}{{ contract.project ? ` · ${contract.project}` : '' }}
                                </p>
                            </div>
                            <UiBadge
                                :tone="contract.expired ? 'danger' : contract.expiring ? 'warning' : 'success'"
                                size="sm"
                                dot
                            >
                                {{ contract.expired ? 'expired' : contract.status }}
                            </UiBadge>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Cover until</dt>
                                <dd class="font-medium">{{ contract.endsOn }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Days left</dt>
                                <dd class="font-medium tnum">{{ contract.daysRemaining }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">First response</dt>
                                <dd class="font-medium">within {{ contract.responseHours }} h</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Resolution</dt>
                                <dd class="font-medium">within {{ contract.resolutionHours }} h</dd>
                            </div>
                            <div v-if="contract.includedTickets">
                                <dt class="text-xs" style="color: var(--text-muted)">Tickets this year</dt>
                                <dd class="font-medium tnum">
                                    {{ contract.ticketsUsed }} of {{ contract.includedTickets }}
                                </dd>
                            </div>
                        </dl>

                        <div v-if="contract.scope.length" class="mt-4">
                            <p class="mb-1.5 text-xs font-medium" style="color: var(--text-muted)">Covered</p>
                            <ul class="space-y-1 text-sm">
                                <li v-for="(item, index) in contract.scope" :key="index" class="flex gap-2">
                                    <span aria-hidden="true" style="color: var(--color-signal-500)">✓</span>
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                        </div>

                        <div v-if="contract.exclusions.length" class="mt-3">
                            <p class="mb-1.5 text-xs font-medium" style="color: var(--text-muted)">Not covered</p>
                            <ul class="space-y-1 text-sm" style="color: var(--text-muted)">
                                <li v-for="(item, index) in contract.exclusions" :key="index" class="flex gap-2">
                                    <span aria-hidden="true">×</span>
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                        </div>
                    </UiCard>
                </div>
            </section>

            <!-- --------------------------------------------------- tickets -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Your tickets
                </h2>

                <UiEmptyState
                    v-if="!tickets.length"
                    :icon="LifeBuoy"
                    title="No tickets"
                    description="Nothing raised yet. That is a good sign."
                />

                <ul v-else class="space-y-2">
                    <li v-for="ticket in tickets" :key="ticket.id">
                        <Link
                            :href="`/client/tickets/${ticket.id}`"
                            class="flex flex-wrap items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-3.5 transition
                                   hover:shadow-[var(--shadow-card)]"
                            style="border-color: var(--border-subtle)"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ ticket.subject }}</span>
                                    <UiBadge :tone="priorityTones[ticket.priority]" size="sm">{{ ticket.priority }}</UiBadge>
                                    <UiBadge v-if="ticket.breached" tone="warning" size="sm">
                                        <AlertTriangle class="mr-1 h-3 w-3" />overdue
                                    </UiBadge>
                                </span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs" style="color: var(--text-muted)">
                                    <span>{{ ticket.reference }}</span>
                                    <span class="inline-flex items-center gap-1">
                                        <Clock class="h-3 w-3" />{{ ticket.ago }}
                                    </span>
                                </span>
                            </span>

                            <UiBadge :tone="ticket.open ? 'brand' : 'neutral'" size="sm" dot>
                                {{ ticket.statusLabel }}
                            </UiBadge>
                            <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
