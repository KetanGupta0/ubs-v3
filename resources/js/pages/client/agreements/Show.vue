<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Download } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';

defineProps({ agreement: { type: Object, required: true } });

const tones = { sent: 'warning', signed: 'brand', countersigned: 'success', superseded: 'neutral', withdrawn: 'danger' };
</script>

<template>
    <Head :title="agreement.title" />

    <AppLayout :title="agreement.number" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Agreements', href: '/client/agreements' }, { label: agreement.number }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="agreement.title" :description="`${agreement.kind} · ${agreement.number}${agreement.version > 1 ? ` · version ${agreement.version}` : ''} · sent ${agreement.sentAt}`">
                <template #actions>
                    <UiButton href="/client/agreements" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Agreements
                    </UiButton>
                    <UiButton :href="`/client/agreements/${agreement.id}/pdf`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        PDF
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[agreement.status] ?? 'neutral'" dot>{{ agreement.statusLabel }}</UiBadge>
                    <span v-if="agreement.effectiveOn" style="color: var(--text-muted)">Effective {{ agreement.effectiveOn }}</span>
                    <span v-if="agreement.expiresOn" style="color: var(--text-muted)">· ends {{ agreement.expiresOn }}</span>
                    <Link v-if="agreement.project" :href="`/client/projects/${agreement.project.id}`" class="hover:underline">· {{ agreement.project.name }}</Link>
                </div>
                <p v-if="agreement.status === 'superseded' && agreement.replacedBy" class="mt-3 text-sm">
                    A later version replaces this one:
                    <Link :href="`/client/agreements/${agreement.replacedBy}`" class="font-medium text-brand-600 hover:underline dark:text-brand-400">read it</Link>.
                </p>
                <p v-if="agreement.status === 'withdrawn'" class="mt-3 text-sm">We withdrew this agreement. It is kept here as it was sent.</p>
            </UiCard>

            <UiCard>
                <MarkdownHtml :html="agreement.html" />
            </UiCard>

            <p v-if="agreement.sha256" class="break-all font-mono text-[0.65rem]" style="color: var(--text-muted)">
                The PDF's SHA-256, recorded when it was sent: {{ agreement.sha256 }}
            </p>
        </div>
    </AppLayout>
</template>
