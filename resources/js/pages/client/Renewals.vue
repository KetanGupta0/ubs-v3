<script setup>
/** Everything of the organisation's that renews or lapses, soonest first (CL16). */
import { Head, Link } from '@inertiajs/vue3';
import { CalendarSync } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({ renewals: { type: Array, default: () => [] } });

const tone = (row) => (row.days < 0 ? 'danger' : row.days <= 14 ? 'warning' : 'neutral');
const label = (row) => (row.days < 0 ? 'Lapsed' : row.days === 0 ? 'Today' : `In ${row.days} ${row.days === 1 ? 'day' : 'days'}`);
</script>

<template>
    <Head title="Renewals" />

    <AppLayout title="Renewals" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Renewals' }]">
        <div class="space-y-5">
            <PageHeader title="Renewals" description="Subscriptions, contracts, domains, hosting and certificates, soonest first. We remind you at 30, 14 and 3 days." />

            <UiCard v-if="!renewals.length">
                <UiEmptyState :icon="CalendarSync" title="Nothing to renew" description="When we look after a domain, hosting or a certificate for you, it appears here." />
            </UiCard>

            <UiCard v-else>
                <ul class="divide-y">
                    <li v-for="(row, index) in renewals" :key="index" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium">
                                <Link v-if="row.href" :href="row.href" class="hover:underline">{{ row.name }}</Link>
                                <template v-else>{{ row.name }}</template>
                            </p>
                            <p class="text-xs" style="color: var(--text-muted)">{{ row.what }}<template v-if="row.note"> · {{ row.note }}</template></p>
                        </div>
                        <span class="text-sm tnum">{{ row.date }}</span>
                        <UiBadge :tone="tone(row)" size="sm" dot>{{ label(row) }}</UiBadge>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
