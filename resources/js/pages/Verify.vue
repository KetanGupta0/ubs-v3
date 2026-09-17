<script setup>
/**
 * Checking a document we issued.
 *
 * Public and unauthenticated on purpose: the person checking is an employer or
 * a college office, and making them sign up to confirm something we issued
 * would defeat the point of issuing it.
 */
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ShieldCheck, ShieldX, Search, BadgeCheck } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';

const props = defineProps({
    code: { type: String, default: '' },
    result: { type: Object, default: null },
    seo: { type: Object, required: true },
});

const entered = ref(props.code ?? '');
const checking = ref(false);

watch(() => props.code, (value) => { entered.value = value ?? ''; });

const check = () => {
    const code = entered.value.trim().toUpperCase();

    if (code === '') return;

    checking.value = true;

    router.get(`/verify/${encodeURIComponent(code)}`, {}, {
        preserveScroll: true,
        onFinish: () => { checking.value = false; },
    });
};
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-2xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Verify</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">Verify a document</h1>
                <p class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">
                    Enter the code printed on a certificate, offer letter or internship document issued by
                    Unboundbyte Solutions. No account is needed.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-2xl px-4 py-10 sm:px-6 lg:px-8">
            <UiCard>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="check">
                    <div class="min-w-[14rem] flex-1">
                        <UiFormField label="Verification code" hint="For example UBS-C-7K4M2QRX. Case does not matter.">
                            <UiInput
                                v-model="entered"
                                placeholder="UBS-…"
                                autocapitalize="characters"
                                spellcheck="false"
                                class="uppercase"
                            />
                        </UiFormField>
                    </div>

                    <UiButton type="submit" :loading="checking" :disabled="!entered.trim()">
                        <template #leading><Search class="h-4 w-4" /></template>
                        Check
                    </UiButton>
                </form>
            </UiCard>

            <!-- ------------------------------------------------- the answer -->
            <UiCard v-if="result && result.found" class="mt-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <component
                            :is="result.valid ? ShieldCheck : ShieldX"
                            class="h-5 w-5"
                            :style="{ color: result.valid ? 'var(--color-signal-500)' : 'var(--color-danger-500)' }"
                        />
                        {{ result.valid ? 'This document is genuine' : 'This document has been revoked' }}
                    </p>

                    <UiBadge :tone="result.valid ? 'success' : 'danger'" size="sm">{{ result.kind }}</UiBadge>
                </div>

                <dl class="mt-5 divide-y text-sm" style="border-color: var(--border-subtle)">
                    <div class="flex flex-wrap justify-between gap-3 py-2.5 first:pt-0">
                        <dt style="color: var(--text-muted)">Issued to</dt>
                        <dd class="font-medium">{{ result.holder }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5">
                        <dt style="color: var(--text-muted)">For</dt>
                        <dd class="font-medium">{{ result.title }}</dd>
                    </div>
                    <div v-if="result.college" class="flex flex-wrap justify-between gap-3 py-2.5">
                        <dt style="color: var(--text-muted)">College</dt>
                        <dd class="font-medium">{{ result.college }}</dd>
                    </div>
                    <div v-if="result.period" class="flex flex-wrap justify-between gap-3 py-2.5">
                        <dt style="color: var(--text-muted)">Period</dt>
                        <dd class="font-medium">{{ result.period }}</dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5">
                        <dt style="color: var(--text-muted)">Issued on</dt>
                        <dd class="font-medium">{{ result.issuedAt }}</dd>
                    </div>
                    <div v-if="result.grade" class="flex flex-wrap justify-between gap-3 py-2.5">
                        <dt style="color: var(--text-muted)">Grade</dt>
                        <dd class="font-medium">
                            {{ result.grade }}<span v-if="result.percent"> · {{ result.percent }}%</span>
                        </dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-3 py-2.5 last:pb-0">
                        <dt style="color: var(--text-muted)">Document number</dt>
                        <dd class="font-medium">{{ result.number }}</dd>
                    </div>
                </dl>

                <p
                    v-if="!result.valid && result.revokedReason"
                    class="mt-4 rounded-[var(--radius-control)] p-3 text-xs"
                    style="background: var(--surface-sunken); color: var(--text-muted)"
                >
                    {{ result.revokedReason }}
                </p>

                <p class="mt-5 text-xs" style="color: var(--text-muted)">
                    Only what is needed to confirm the document is shown. We do not publish a holder's contact
                    details on a page anybody can reach.
                </p>
            </UiCard>

            <UiCard v-else-if="result" class="mt-5">
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <ShieldX class="h-5 w-5" style="color: var(--color-warn-500)" />
                    No document matches that code
                </p>
                <p class="mt-2 text-sm" style="color: var(--text-muted)">
                    Check the code against the printed copy — the letter O and the digit zero are the usual culprits,
                    which is why our codes use neither. If it still does not match, write to us and we will confirm
                    it by hand.
                </p>
                <UiButton href="/contact" variant="secondary" size="sm" class="mt-4">Contact us</UiButton>
            </UiCard>

            <p v-else class="mt-6 flex items-start gap-2 px-1 text-xs" style="color: var(--text-muted)">
                <BadgeCheck class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Every certificate and internship document we issue carries a code and this page. A document
                    without one did not come from us.
                </span>
            </p>
        </section>
    </PublicLayout>
</template>
