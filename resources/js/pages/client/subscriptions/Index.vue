<script setup>
import { Head, router } from '@inertiajs/vue3';
import { RefreshCw, AlertTriangle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    subscriptions: { type: Array, default: () => [] },
    renewingSoon: { type: Number, default: 0 },
});

function setAutoRenew(subscription, value) {
    router.put(
        `/client/subscriptions/${subscription.id}/auto-renew`,
        { auto_renew: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Subscriptions" />

    <AppLayout title="Subscriptions" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Subscriptions' }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                title="Subscriptions"
                :description="renewingSoon
                    ? `${renewingSoon} ${renewingSoon === 1 ? 'renewal is' : 'renewals are'} coming up in the next 30 days.`
                    : 'Everything on your account that renews.'"
            />

            <UiEmptyState
                v-if="!subscriptions.length"
                :icon="RefreshCw"
                title="Nothing renews yet"
                description="Hosting, maintenance and API plans appear here once they start."
            />

            <UiCard v-for="subscription in subscriptions" :key="subscription.id">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-base font-semibold">
                            {{ subscription.name }}
                            <UiBadge
                                :tone="subscription.lapsed ? 'danger' : subscription.status === 'active' ? 'success' : 'neutral'"
                                size="sm"
                                dot
                            >
                                {{ subscription.lapsed ? 'lapsed' : subscription.status }}
                            </UiBadge>
                            <UiBadge
                                v-if="!subscription.lapsed && subscription.daysToRenewal <= 30 && subscription.status === 'active'"
                                tone="warning"
                                size="sm"
                            >
                                renews in {{ subscription.daysToRenewal }} days
                            </UiBadge>
                        </p>

                        <p v-if="subscription.description" class="mt-1 text-sm" style="color: var(--text-muted)">
                            {{ subscription.description }}
                        </p>

                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Amount</dt>
                                <dd class="font-medium tnum">{{ subscription.amount }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Billed</dt>
                                <dd class="font-medium">{{ subscription.interval }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Next renewal</dt>
                                <dd class="font-medium">{{ subscription.renewsOn }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs" style="color: var(--text-muted)">Started</dt>
                                <dd class="font-medium">{{ subscription.startsOn }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="w-full sm:w-auto">
                        <UiSwitch
                            :model-value="subscription.autoRenew"
                            label="Renew automatically"
                            description="We will still tell you before it happens."
                            :disabled="subscription.status !== 'active'"
                            @update:model-value="setAutoRenew(subscription, $event)"
                        />
                    </div>
                </div>

                <p
                    v-if="subscription.lapsed"
                    class="mt-4 flex items-start gap-2 rounded-[var(--radius-field)] px-3 py-2 text-xs"
                    style="background: var(--surface-sunken); color: var(--text-muted)"
                >
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                    The renewal date has passed. Get in touch and we will sort it out.
                </p>
            </UiCard>

            <p class="text-xs" style="color: var(--text-muted)">
                To stop a subscription altogether, talk to us rather than switching off automatic renewal: hosting
                turned off by mistake takes a site down.
            </p>
        </div>
    </AppLayout>
</template>
