<script setup>
/**
 * A change to the project's scope (CL12), from the client's side.
 *
 * Once we have estimated it, a signatory or an owner approves it by signing
 * the addendum, which updates the project's value and dates and adds the
 * work as a milestone; or declines it with a reason.
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download, XCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import SignDocument from '@/components/Documents/SignDocument.vue';
import SignatureList from '@/components/Documents/SignatureList.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    change: { type: Object, required: true },
    canAnswer: { type: Boolean, default: false },
});

const base = `/client/projects/${props.change.project.id}/changes/${props.change.id}`;
const tones = { requested: 'warning', quoted: 'brand', approved: 'success', declined: 'danger', withdrawn: 'neutral' };

const declining = ref(false);
const declineForm = useForm({ reason: '' });

function decline() {
    declineForm.post(`${base}/decline`, { preserveScroll: true, onSuccess: () => (declining.value = false) });
}
</script>

<template>
    <Head :title="change.title" />

    <AppLayout
        :title="change.title"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Projects', href: '/client/projects' },
            { label: change.project.name, href: `/client/projects/${change.project.id}` },
            { label: change.number },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="change.title" :description="`${change.number} · ${change.project.name}`">
                <template #actions>
                    <UiButton :href="`/client/projects/${change.project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                    <UiButton v-if="change.hasPdf" :href="`${base}/pdf`" :inertia="false" variant="secondary" size="sm">
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        Addendum PDF
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <UiBadge :tone="tones[change.status] ?? 'neutral'" dot>{{ change.statusLabel }}</UiBadge>
                    <span style="color: var(--text-muted)">Asked {{ change.askedAt }}</span>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm">{{ change.description }}</p>
                <p v-if="change.status === 'requested'" class="mt-3 text-sm" style="color: var(--text-muted)">We are estimating it, and will send it to your signatories to approve.</p>
            </UiCard>

            <UiCard v-if="change.amount">
                <h2 class="text-base font-semibold" style="color: var(--text-strong)">Our estimate</h2>
                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt style="color: var(--text-muted)">Amount</dt><dd class="font-medium">{{ change.amount }} before tax</dd></div>
                    <div><dt style="color: var(--text-muted)">Timeline</dt><dd class="font-medium">{{ change.days }}</dd></div>
                </dl>
                <p v-if="change.estimateNote" class="mt-3 whitespace-pre-line text-sm">{{ change.estimateNote }}</p>
                <p v-if="change.status === 'approved'" class="mt-3 text-sm">
                    Approved {{ change.decidedAt }}<template v-if="change.decidedBy"> by {{ change.decidedBy }}</template>. The work is now a milestone on
                    <Link :href="`/client/projects/${change.project.id}`" class="font-medium text-brand-600 hover:underline dark:text-brand-400">the project</Link>, invoiced when you accept it.
                </p>
                <p v-if="change.status === 'declined'" class="mt-3 text-sm">Declined {{ change.decidedAt }}: “{{ change.declineReason }}”</p>
                <p v-if="change.status === 'quoted' && !canAnswer" class="mt-3 text-sm" style="color: var(--text-muted)">An authorised signatory or an owner in your organisation approves changes. It has been sent to them.</p>
            </UiCard>

            <template v-if="canAnswer">
                <SignDocument
                    :code-url="`${base}/sign/code`"
                    :sign-url="`${base}/sign`"
                    :pdf-url="`${base}/pdf`"
                    :sha256="change.sha256"
                    heading="Approve by signing"
                    intro="Approving adds this change to the project's value and dates."
                    confirm-label="I have read the addendum and approve the change for my organisation."
                    button-label="Approve and sign"
                />
                <p class="text-sm" style="color: var(--text-muted)">
                    Not right?
                    <button type="button" class="font-medium text-danger hover:underline" @click="declining = true">Decline it</button>
                    and tell us why; we can estimate it again.
                </p>
            </template>

            <SignatureList :signatures="change.signatures" :signed-url="change.hasSignedCopy ? `${base}/signed` : null" />
        </div>

        <UiModal :open="declining" title="Decline this change?" description="Tell us why. We can estimate it again differently." @close="declining = false">
            <form id="decline-form" @submit.prevent="decline">
                <UiFormField label="Why" required :error="declineForm.errors.reason">
                    <UiTextarea v-model="declineForm.reason" :rows="4" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="declining = false">Cancel</UiButton>
                <UiButton type="submit" form="decline-form" variant="danger" :loading="declineForm.processing" :disabled="declineForm.reason.trim().length < 5">
                    <template #leading><XCircle class="h-4 w-4" /></template>
                    Decline
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
