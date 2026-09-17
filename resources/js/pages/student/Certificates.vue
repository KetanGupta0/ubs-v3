<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Award, Download, Copy, Check, FileBadge, ShieldCheck, ShieldX } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    certificates: { type: Array, default: () => [] },
    internshipDocuments: { type: Array, default: () => [] },
});

const copied = ref(null);

const copy = async (url, key) => {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = key;
        setTimeout(() => { copied.value = null; }, 2000);
    } catch {
        copied.value = null;
    }
};
</script>

<template>
    <Head title="Certificates" />

    <AppLayout title="Certificates" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Certificates' }]">
        <div class="mx-auto max-w-3xl space-y-6">
            <PageHeader
                title="Certificates and documents"
                description="Everything we have issued you. Each one carries a code anybody can check without an account."
            />

            <UiEmptyState
                v-if="!certificates.length && !internshipDocuments.length"
                :icon="Award"
                title="Nothing issued yet"
                description="A certificate is issued once a course is complete and you are above its pass mark."
            />

            <!-- ---------------------------------------------- certificates -->
            <section v-if="certificates.length" class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Certificates
                </h2>

                <UiCard v-for="certificate in certificates" :key="certificate.id">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                <Award class="h-4 w-4 shrink-0" style="color: var(--color-brand-500)" />
                                {{ certificate.title }}
                                <UiBadge v-if="!certificate.valid" tone="danger" size="sm">revoked</UiBadge>
                            </p>
                            <p class="mt-1 text-xs" style="color: var(--text-muted)">
                                {{ certificate.number }} · issued {{ certificate.issuedAt }}
                                <span v-if="certificate.grade"> · grade {{ certificate.grade }}</span>
                                <span v-if="certificate.percent"> · {{ certificate.percent }}%</span>
                            </p>
                        </div>

                        <UiButton
                            v-if="certificate.valid"
                            :href="`/student/certificates/${certificate.id}/download`"
                            :inertia="false"
                            variant="secondary"
                            size="sm"
                        >
                            <template #leading><Download class="h-3.5 w-3.5" /></template>
                            PDF
                        </UiButton>
                    </div>

                    <div
                        class="mt-4 flex flex-wrap items-center gap-2 rounded-[var(--radius-control)] p-3"
                        style="background: var(--surface-sunken)"
                    >
                        <component
                            :is="certificate.valid ? ShieldCheck : ShieldX"
                            class="h-4 w-4 shrink-0"
                            :style="{ color: certificate.valid ? 'var(--color-signal-500)' : 'var(--color-danger-500)' }"
                        />
                        <span class="text-xs" style="color: var(--text-muted)">Verification code</span>
                        <code class="text-xs font-semibold tracking-wider">{{ certificate.code }}</code>

                        <UiButton
                            variant="ghost"
                            size="xs"
                            class="ml-auto"
                            @click="copy(certificate.verificationUrl, `c${certificate.id}`)"
                        >
                            <template #leading>
                                <Check v-if="copied === `c${certificate.id}`" class="h-3 w-3" />
                                <Copy v-else class="h-3 w-3" />
                            </template>
                            {{ copied === `c${certificate.id}` ? 'Link copied' : 'Copy check link' }}
                        </UiButton>
                    </div>
                </UiCard>
            </section>

            <!-- ---------------------------------------- internship papers -->
            <section v-if="internshipDocuments.length" class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Internship documents
                </h2>

                <UiCard padding="p-0">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="document in internshipDocuments"
                            :key="document.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        >
                            <FileBadge class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium">{{ document.kindLabel }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ document.course }} · {{ document.number }} · {{ document.issuedAt }}
                                </span>
                            </span>

                            <code class="shrink-0 text-xs tracking-wider" style="color: var(--text-muted)">
                                {{ document.code }}
                            </code>

                            <UiButton
                                :href="`/student/documents/${document.id}/download`"
                                :inertia="false"
                                variant="ghost"
                                size="xs"
                            >
                                <template #leading><Download class="h-3 w-3" /></template>
                                PDF
                            </UiButton>
                        </li>
                    </ul>
                </UiCard>

                <p class="px-1 text-xs" style="color: var(--text-muted)">
                    Your college can check any of these at
                    <a href="/verify" class="underline" target="_blank" rel="noopener">{{ '/verify' }}</a>
                    with the code printed on the document. No account needed.
                </p>
            </section>
        </div>
    </AppLayout>
</template>
