<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ScrollText } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({ agreements: { type: Array, default: () => [] } });

const tones = { sent: 'warning', signed: 'brand', countersigned: 'success', superseded: 'neutral', withdrawn: 'danger' };
</script>

<template>
    <Head title="Agreements" />

    <AppLayout title="Agreements" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Agreements' }]">
        <div>
            <PageHeader title="Agreements" description="Everything we have agreed in writing, each as the PDF it was sent as." />

            <UiEmptyState v-if="!agreements.length" :icon="ScrollText" title="No agreements yet" description="Agreements we send you appear here." />

            <ul v-else class="space-y-3">
                <li v-for="agreement in agreements" :key="agreement.id">
                    <UiCard padding="p-4">
                        <Link :href="`/client/agreements/${agreement.id}`" class="flex flex-wrap items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block font-medium hover:underline" style="color: var(--text-strong)">{{ agreement.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ agreement.kind }} · {{ agreement.number }}{{ agreement.version > 1 ? ` · version ${agreement.version}` : '' }}{{ agreement.project ? ` · ${agreement.project}` : '' }} · {{ agreement.sentAt }}
                                </span>
                            </span>
                            <UiBadge :tone="tones[agreement.status] ?? 'neutral'" size="sm" dot>{{ agreement.statusLabel }}</UiBadge>
                        </Link>
                    </UiCard>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
