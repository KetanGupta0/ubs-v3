<script setup>
/**
 * Who signed a document, when and how it was proved: the same details as
 * the certificate of signing at the end of the signed copy.
 */
import { BadgeCheck, Download } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';

defineProps({
    signatures: { type: Array, default: () => [] },
    signedUrl: { type: String, default: null },
    /** Staff see where each signature came from. */
    detailed: { type: Boolean, default: false },
});
</script>

<template>
    <UiCard v-if="signatures.length">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-base font-semibold" style="color: var(--text-strong)">Signatures</h2>
            <UiButton v-if="signedUrl" :href="signedUrl" :inertia="false" variant="secondary" size="sm">
                <template #leading><Download class="h-3.5 w-3.5" /></template>
                Signed copy
            </UiButton>
        </div>

        <ul class="mt-4 space-y-3">
            <li v-for="(signature, index) in signatures" :key="index" class="flex items-start gap-3 rounded-xl border p-3" style="border-color: var(--border-subtle)">
                <BadgeCheck class="mt-0.5 h-5 w-5 shrink-0 text-green-600 dark:text-green-400" aria-hidden="true" />
                <div class="min-w-0 text-sm">
                    <p style="color: var(--text-muted)">
                        {{ signature.party }} <span class="font-medium" style="color: var(--text-strong)">{{ signature.name }}</span>{{ signature.designation ? `, ${signature.designation}` : '' }}{{ signature.organisation ? `, ${signature.organisation}` : '' }}
                    </p>
                    <p class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ signature.signedAt }} · {{ signature.verified }}</p>
                    <p v-if="detailed" class="mt-0.5 break-all text-xs" style="color: var(--text-muted)">{{ signature.email }} · from {{ signature.ip }}</p>
                </div>
            </li>
        </ul>
    </UiCard>
</template>
