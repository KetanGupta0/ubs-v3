<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { FileText, ArrowRight, Clock } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    proposals: { type: Array, default: () => [] },
    awaiting: { type: Number, default: 0 },
});

const tones = { accepted: 'success', rejected: 'danger', expired: 'neutral', sent: 'brand' };
</script>

<template>
    <Head title="Proposals" />

    <AppLayout title="Proposals" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Proposals' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                title="Proposals"
                :description="awaiting
                    ? `${awaiting} ${awaiting === 1 ? 'proposal is' : 'proposals are'} waiting for your answer.`
                    : 'What we have proposed, and what you said about it.'"
            />

            <UiEmptyState
                v-if="!proposals.length"
                :icon="FileText"
                title="No proposals yet"
                description="Anything we quote for you will appear here to read and respond to."
            />

            <UiCard v-for="proposal in proposals" :key="proposal.id" interactive as="article">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2">
                            <Link :href="`/client/proposals/${proposal.id}`" class="text-base font-semibold hover:underline">
                                {{ proposal.title }}
                            </Link>
                            <UiBadge :tone="tones[proposal.status] ?? 'neutral'" size="sm" dot>
                                {{ proposal.statusLabel }}
                            </UiBadge>
                            <UiBadge v-if="proposal.version > 1" size="sm">version {{ proposal.version }}</UiBadge>
                        </p>

                        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" style="color: var(--text-muted)">
                            <span>{{ proposal.number }}</span>
                            <span v-if="proposal.sentAt">Sent {{ proposal.sentAt }}</span>
                            <span v-if="proposal.validUntil" class="inline-flex items-center gap-1">
                                <Clock class="h-3 w-3" />
                                {{ proposal.expired ? 'Expired' : 'Valid until' }} {{ proposal.validUntil }}
                            </span>
                        </p>
                    </div>

                    <div class="text-right">
                        <p v-if="proposal.total" class="text-lg font-semibold tnum">{{ proposal.total }}</p>
                        <UiButton :href="`/client/proposals/${proposal.id}`" size="sm" class="mt-2">
                            {{ proposal.canRespond ? 'Read and respond' : 'Open' }}
                            <template #trailing><ArrowRight class="h-3 w-3" /></template>
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
