<script setup>
/**
 * One change request (CL12): what was asked, our estimate, and the answer.
 * Quoting fixes the text and the PDF; a different estimate is a new request.
 */
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, CopyPlus, Download, Eye, Send, Undo2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import SignatureList from '@/components/Documents/SignatureList.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({ change: { type: Object, required: true } });

const base = `/admin/change-requests/${props.change.id}`;
const tones = { requested: 'warning', quoted: 'brand', approved: 'success', declined: 'danger', withdrawn: 'neutral' };

const quoteForm = useForm({ amount: '', days: 0, estimate_note: '' });

// The preview renders what is typed here; nothing is saved until it is sent.
const previewHref = computed(() => `${base}/pdf?${new URLSearchParams({
    amount: quoteForm.amount || 0,
    days: quoteForm.days || 0,
    note: (quoteForm.estimate_note ?? '').slice(0, 1500),
})}`);

function quote() {
    if (window.confirm(`Send ${props.change.number} to the client at ₹${quoteForm.amount} before tax? Its text and PDF are fixed from that moment.`)) {
        quoteForm.post(`${base}/quote`, { preserveScroll: true });
    }
}

function withdraw() {
    if (window.confirm('Withdraw this change request? The client can no longer approve it.')) router.post(`${base}/withdraw`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="change.number" />

    <AppLayout :title="change.number" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Change requests', href: '/admin/change-requests' }, { label: change.number }]">
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="change.title" :description="`${change.number} · ${change.client}${change.project ? ` · ${change.project.name}` : ''}`">
                <template #actions>
                    <UiButton href="/admin/change-requests" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Change requests
                    </UiButton>
                    <UiButton v-if="change.hasPdf" :href="`${base}/pdf`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        PDF as sent
                    </UiButton>
                    <UiButton v-if="change.status === 'requested' || change.status === 'quoted'" variant="ghost" size="sm" @click="withdraw">
                        <template #leading><Undo2 class="h-3.5 w-3.5" /></template>
                        Withdraw
                    </UiButton>
                    <UiButton v-if="change.status === 'declined' || change.status === 'withdrawn'" size="sm" @click="router.post(`${base}/again`)">
                        <template #leading><CopyPlus class="h-3.5 w-3.5" /></template>
                        Quote it again
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[change.status] ?? 'neutral'" dot>{{ change.statusLabel }}</UiBadge>
                    <span style="color: var(--text-muted)">Asked {{ change.askedAt }}<template v-if="change.raisedBy"> by {{ change.raisedBy }}</template></span>
                    <span v-if="change.viewedAt" style="color: var(--text-muted)">· opened {{ change.viewedAt }}<template v-if="change.viewedBy">, {{ change.viewedBy }}</template></span>
                    <Link v-if="change.project" :href="`/admin/projects/${change.project.id}`" class="hover:underline" style="color: var(--text-muted)">· {{ change.project.name }}</Link>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm">{{ change.description }}</p>
                <p v-if="change.previous" class="mt-3 text-xs" style="color: var(--text-muted)">
                    Follows <Link :href="`/admin/change-requests/${change.previous.id}`" class="hover:underline">{{ change.previous.number }}</Link>.
                </p>
                <p v-for="next in change.next" :key="next.id" class="mt-1 text-xs" style="color: var(--text-muted)">
                    Quoted again as <Link :href="`/admin/change-requests/${next.id}`" class="hover:underline">{{ next.number }}</Link>.
                </p>
            </UiCard>

            <!-- ------------------------------------------------- estimating -->
            <UiCard v-if="change.status === 'requested'">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Estimate it</h2>
                <p class="mt-1 text-xs" style="color: var(--text-muted)">Sent as a quotation addendum. The client's signatories approve it by signing; it is then added to the project's value and dates, and becomes a milestone invoiced on acceptance.</p>
                <form class="mt-4 space-y-4" @submit.prevent="quote">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <UiFormField label="Amount before tax (₹)" required :error="quoteForm.errors.amount">
                            <UiInput v-model="quoteForm.amount" type="number" min="0" step="0.01" />
                        </UiFormField>
                        <UiFormField label="Extra days on the timeline" required :error="quoteForm.errors.days">
                            <UiInput v-model="quoteForm.days" type="number" min="0" max="730" />
                        </UiFormField>
                    </div>
                    <UiFormField label="What we will do" hint="Optional: approach, what is included, assumptions." :error="quoteForm.errors.estimate_note">
                        <UiTextarea v-model="quoteForm.estimate_note" :rows="5" />
                    </UiFormField>
                    <div class="flex flex-wrap justify-end gap-2">
                        <UiButton :href="previewHref" :inertia="false" target="_blank" variant="secondary">
                            <template #leading><Eye class="h-4 w-4" /></template>
                            Preview
                        </UiButton>
                        <UiButton type="submit" :loading="quoteForm.processing" :disabled="quoteForm.amount === ''">
                            <template #leading><Send class="h-4 w-4" /></template>
                            Send to the client
                        </UiButton>
                    </div>
                </form>
            </UiCard>

            <UiCard v-else-if="change.amount">
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt style="color: var(--text-muted)">Estimate</dt><dd class="font-medium">{{ change.amount }} before tax</dd></div>
                    <div><dt style="color: var(--text-muted)">Timeline</dt><dd class="font-medium">{{ change.days }}</dd></div>
                    <div><dt style="color: var(--text-muted)">Sent</dt><dd class="font-medium">{{ change.quotedAt }}<template v-if="change.quotedBy"> · {{ change.quotedBy }}</template></dd></div>
                </dl>
                <p v-if="change.estimateNote" class="mt-3 whitespace-pre-line text-sm">{{ change.estimateNote }}</p>
                <p v-if="change.status === 'declined'" class="mt-3 text-sm">Declined by {{ change.decidedBy }}, {{ change.decidedAt }}: “{{ change.declineReason }}”</p>
                <p v-if="change.status === 'approved' && change.milestone" class="mt-3 text-sm">Approved {{ change.decidedAt }}. Added to the project as the milestone “{{ change.milestone }}”.</p>
                <p v-if="change.sha256" class="mt-3 break-all font-mono text-[0.65rem]" style="color: var(--text-muted)">SHA-256 {{ change.sha256 }}</p>
            </UiCard>

            <SignatureList :signatures="change.signatures" :signed-url="change.hasSignedCopy ? `${base}/signed` : null" detailed />
        </div>
    </AppLayout>
</template>
