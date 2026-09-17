<script setup>
/**
 * Every notice sent on one batch.
 *
 * The private note is shown here and nowhere the student can reach, which is
 * the only thing that makes it worth writing one honestly.
 */
import { Head, router } from '@inertiajs/vue3';
import { ArrowLeft, ShieldAlert, Check, BellRing } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    warnings: { type: Array, default: () => [] },
});

const tones = { notice: 'brand', warning: 'warning', escalation: 'danger' };

function resolve(warning) {
    router.post(`/admin/batches/${props.batch.id}/warnings/${warning.id}/resolve`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Notices — ${batch.name}`" />

    <AppLayout
        title="Notices"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: batch.name, href: `/admin/batches/${batch.id}/run` },
            { label: 'Notices' },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-4">
            <PageHeader
                title="Notices sent"
                :description="`${batch.course} · ${batch.name}. Sent while there is still time to act on them, not at the end.`"
            >
                <template #actions>
                    <UiButton :href="`/admin/batches/${batch.id}/run`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Batch
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!warnings.length"
                :icon="ShieldAlert"
                title="None sent"
                description="Send the first from the student list on the batch screen."
            />

            <UiCard v-for="warning in warnings" :key="warning.id" padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ warning.student }}
                            <UiBadge :tone="tones[warning.level] ?? 'neutral'" size="sm">{{ warning.level }}</UiBadge>
                            <UiBadge v-if="warning.resolved" tone="success" size="sm">closed</UiBadge>
                            <UiBadge v-else-if="warning.acknowledged" size="sm">read</UiBadge>
                            <UiBadge v-if="warning.guardianNotified" tone="accent" size="sm">
                                <BellRing class="mr-1 h-3 w-3" />guardian told
                            </UiBadge>
                        </p>

                        <p class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">
                            {{ warning.reason }}
                        </p>

                        <p
                            v-if="warning.privateNote"
                            class="mt-2 rounded-[var(--radius-control)] p-2.5 text-xs"
                            style="background: var(--surface-sunken); color: var(--text-muted)"
                        >
                            Internal: {{ warning.privateNote }}
                        </p>

                        <p class="mt-2 text-xs" style="color: var(--text-muted)">
                            {{ [warning.issuedBy, warning.session, warning.at].filter(Boolean).join(' · ') }}
                        </p>
                    </div>

                    <UiButton v-if="!warning.resolved" variant="secondary" size="xs" @click="resolve(warning)">
                        <template #leading><Check class="h-3 w-3" /></template>
                        Close out
                    </UiButton>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
