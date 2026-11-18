<script setup>
/**
 * One send of greetings or an announcement (§8): who it was for, how many
 * went, why the rest did not, and who stopped them because of it.
 */
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    table: { type: Object, required: true },
});

const tone = { draft: 'neutral', scheduled: 'info', sending: 'warning', sent: 'success', cancelled: 'neutral' };
const rowTone = (key) => ({ sent: 'success', queued: 'info', skipped: 'neutral', failed: 'danger' })[key] ?? 'neutral';

function cancel() {
    if (window.confirm('Cancel it? Nothing more of it goes out. What has gone stays gone.')) {
        router.post(`/admin/mail/broadcasts/${props.campaign.id}/cancel`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="campaign.subject" />

    <AppLayout :title="campaign.subject" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Announcements', href: '/admin/mail/broadcasts' }, { label: campaign.subject }]">
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader :title="campaign.subject" :description="campaign.occasion ? `The greeting for ${campaign.occasion}.` : 'An announcement.'">
                <template #actions>
                    <UiButton href="/admin/mail/broadcasts" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Announcements
                    </UiButton>
                    <UiButton v-if="campaign.canEdit" :href="`/admin/mail/broadcasts/${campaign.id}/edit`" variant="secondary" size="sm">
                        <template #leading><Pencil class="h-3.5 w-3.5" /></template>
                        Change
                    </UiButton>
                    <UiButton v-if="campaign.canCancel" variant="ghost" size="sm" @click="cancel">Cancel it</UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3">
                    <UiBadge :tone="tone[campaign.status] ?? 'neutral'" dot>{{ campaign.statusLabel }}</UiBadge>
                    <span class="text-sm" style="color: var(--text-muted)">
                        <template v-if="campaign.startedAt">Started {{ campaign.startedAt }}</template>
                        <template v-else-if="campaign.scheduledAt">Goes {{ campaign.scheduledAt }}</template>
                        <template v-if="campaign.finishedAt"> · finished {{ campaign.finishedAt }}</template>
                        <template v-if="campaign.expiresAt && campaign.status === 'sending'"> · stops at {{ campaign.expiresAt }}</template>
                    </span>
                </div>
                <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <div v-for="[label, value] in [
                        ['For', campaign.counts.recipients],
                        ['Sent', campaign.counts.sent],
                        ['Waiting', campaign.counts.queued],
                        ['Not sent', campaign.counts.skipped],
                        ['Failed', campaign.counts.failed],
                        ['Stopped them', campaign.counts.unsubscribed],
                    ]" :key="label" class="rounded-[var(--radius-control)] p-3" style="background: var(--surface-sunken)">
                        <dt class="text-xs" style="color: var(--text-muted)">{{ label }}</dt>
                        <dd class="mt-0.5 text-lg font-semibold tnum">{{ value }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs" style="color: var(--text-muted)">Everybody with an account sees it in their notifications as soon as it starts. Opens are not counted: the mail carries no tracking.</p>
            </UiCard>

            <DataTable :table="table" search-placeholder="Search by address or name…" empty-title="Nobody yet" empty-description="Who it is for is fixed when it starts.">
                <template #cell:status="{ row }">
                    <UiBadge :tone="rowTone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium">{{ row.email }}</span>
                        <UiBadge :tone="rowTone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.name }} · {{ row.who }} · {{ row.sent_at }}</p>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
