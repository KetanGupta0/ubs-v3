<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, CopyPlus, Download, Eye, FileCheck2, Pencil, Send, Trash2, Undo2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import SignDocument from '@/components/Documents/SignDocument.vue';
import SignatureList from '@/components/Documents/SignatureList.vue';

const props = defineProps({
    agreement: { type: Object, required: true },
    canCountersign: { type: Boolean, default: false },
});

const tones = { draft: 'neutral', sent: 'warning', signed: 'brand', countersigned: 'success', superseded: 'neutral', withdrawn: 'danger' };
const base = `/admin/agreements/${props.agreement.id}`;

function send() {
    if (window.confirm(`Send ${props.agreement.number} to ${props.agreement.client}? Its text and PDF are fixed from that moment.`)) {
        router.post(`${base}/send`);
    }
}

function withdraw() {
    if (window.confirm('Withdraw it? The client still sees it, marked withdrawn.')) router.post(`${base}/withdraw`);
}

function destroy() {
    if (window.confirm('Delete this draft?')) router.delete(base);
}
</script>

<template>
    <Head :title="agreement.number" />

    <AppLayout
        :title="agreement.number"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Agreements', href: '/admin/agreements' }, { label: agreement.number }]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="agreement.title" :description="`${agreement.kind} · ${agreement.number}${agreement.version > 1 ? ` · version ${agreement.version}` : ''} · ${agreement.client}`">
                <template #actions>
                    <UiButton href="/admin/agreements" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Agreements
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="space-y-5 lg:col-span-2">
                    <SignDocument
                        v-if="canCountersign"
                        :code-url="`${base}/countersign/code`"
                        :sign-url="`${base}/countersign`"
                        :pdf-url="`${base}/pdf`"
                        :sha256="agreement.sha256"
                        heading="Countersign for the company"
                        intro="The client has signed. Countersigning binds the company to it."
                        confirm-label="I have read the agreement and countersign it for the company, with authority to do so."
                        button-label="Countersign"
                    />

                    <SignatureList :signatures="agreement.signatures" :signed-url="agreement.hasSignedCopy ? `${base}/signed` : null" detailed />

                    <UiCard>
                        <MarkdownHtml :html="agreement.html" />
                    </UiCard>
                </div>

                <div class="space-y-5">
                    <UiCard>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold">Status</span>
                            <UiBadge :tone="tones[agreement.status] ?? 'neutral'" dot>{{ agreement.statusLabel }}</UiBadge>
                        </div>
                        <dl class="mt-3 space-y-1.5 text-sm">
                            <div v-if="agreement.sentAt" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Sent</dt><dd>{{ agreement.sentAt }}</dd></div>
                            <div v-if="agreement.sentAt" class="flex justify-between gap-2">
                                <dt style="color: var(--text-muted)">Opened</dt>
                                <dd class="text-right">{{ agreement.viewedAt ? `${agreement.viewedAt}${agreement.viewedBy ? `, ${agreement.viewedBy}` : ''}` : 'Not yet' }}</dd>
                            </div>
                            <div v-if="agreement.effectiveOn" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Effective</dt><dd>{{ agreement.effectiveOn }}</dd></div>
                            <div v-if="agreement.expiresOn" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Ends</dt><dd>{{ agreement.expiresOn }}</dd></div>
                            <div v-if="agreement.project" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Project</dt><dd><Link :href="`/admin/projects/${agreement.project.id}`" class="hover:underline">{{ agreement.project.name }}</Link></dd></div>
                            <div v-if="agreement.contract" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Contract</dt><dd>{{ agreement.contract }}</dd></div>
                            <div v-if="agreement.subscription" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Subscription</dt><dd>{{ agreement.subscription }}</dd></div>
                            <div v-if="agreement.previous" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Replaces</dt><dd><Link :href="`/admin/agreements/${agreement.previous.id}`" class="hover:underline">{{ agreement.previous.number }}</Link></dd></div>
                            <div v-for="later in agreement.later" :key="later.id" class="flex justify-between gap-2"><dt style="color: var(--text-muted)">Next version</dt><dd><Link :href="`/admin/agreements/${later.id}`" class="hover:underline">{{ later.number }}</Link> ({{ later.status.toLowerCase() }})</dd></div>
                        </dl>
                        <p v-if="agreement.sha256" class="mt-3 break-all font-mono text-[0.65rem]" style="color: var(--text-muted)" title="SHA-256 of the PDF as sent">SHA-256 {{ agreement.sha256 }}</p>
                    </UiCard>

                    <UiCard>
                        <div class="flex flex-col gap-2">
                            <template v-if="agreement.status === 'draft'">
                                <UiButton :href="`${base}/edit`" variant="secondary" size="sm">
                                    <template #leading><Pencil class="h-3.5 w-3.5" /></template>
                                    Edit the draft
                                </UiButton>
                                <UiButton :href="`${base}/pdf`" :inertia="false" target="_blank" variant="secondary" size="sm">
                                    <template #leading><Eye class="h-3.5 w-3.5" /></template>
                                    Preview the PDF
                                </UiButton>
                                <UiButton size="sm" @click="send">
                                    <template #leading><Send class="h-3.5 w-3.5" /></template>
                                    Send to the client
                                </UiButton>
                                <UiButton variant="ghost" size="sm" @click="destroy">
                                    <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                                    Delete the draft
                                </UiButton>
                            </template>
                            <template v-else>
                                <UiButton :href="`${base}/pdf`" :inertia="false" variant="secondary" size="sm">
                                    <template #leading><Download class="h-3.5 w-3.5" /></template>
                                    Download the PDF as sent
                                </UiButton>
                                <UiButton v-if="agreement.hasSignedCopy" :href="`${base}/signed`" :inertia="false" variant="secondary" size="sm">
                                    <template #leading><FileCheck2 class="h-3.5 w-3.5" /></template>
                                    Download the signed copy
                                </UiButton>
                                <UiButton variant="secondary" size="sm" @click="router.post(`${base}/revise`)">
                                    <template #leading><CopyPlus class="h-3.5 w-3.5" /></template>
                                    Start a new version
                                </UiButton>
                                <UiButton v-if="agreement.status === 'sent'" variant="ghost" size="sm" @click="withdraw">
                                    <template #leading><Undo2 class="h-3.5 w-3.5" /></template>
                                    Withdraw
                                </UiButton>
                            </template>
                        </div>
                    </UiCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
