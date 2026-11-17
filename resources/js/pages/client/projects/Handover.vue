<script setup>
/**
 * The handover pack (CL15): what we handed over, kept for whenever it is
 * needed. A signatory or an owner acknowledges it; then a short survey
 * (CL18), whose quote we publish only with written consent.
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, BadgeCheck, ExternalLink, PlayCircle, ShieldCheck } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    project: { type: Object, required: true },
    handover: { type: Object, required: true },
    canAcknowledge: { type: Boolean, default: false },
    surveyAnswered: { type: Boolean, default: false },
});

const base = `/client/projects/${props.project.id}/handover`;
const survey = useForm({ rating: null, went_well: '', could_improve: '', quote: '', consent_to_publish: false, credit_as: '' });

function acknowledge() {
    router.post(`${base}/acknowledge`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Handover · ${project.name}`" />

    <AppLayout :title="`Handover · ${project.name}`" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Projects', href: '/client/projects' }, { label: project.code ?? project.name, href: `/client/projects/${project.id}` }, { label: 'Handover' }]">
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="`Handover · ${project.name}`" :description="`Revision ${handover.revision}, ${handover.publishedAt}.`">
                <template #actions>
                    <UiButton :href="`/client/projects/${project.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Project
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <UiBadge v-if="handover.acknowledged" tone="success" dot>Acknowledged by {{ handover.acknowledgedBy }}, {{ handover.acknowledgedAt }}</UiBadge>
                        <UiBadge v-else tone="warning" dot>Waiting for your acknowledgement</UiBadge>
                        <span v-if="handover.warrantyEndsOn" class="inline-flex items-center gap-1" style="color: var(--text-muted)"><ShieldCheck class="h-4 w-4" aria-hidden="true" /> Warranty until {{ handover.warrantyEndsOn }}</span>
                    </div>
                    <UiButton v-if="canAcknowledge" size="sm" @click="acknowledge">
                        <template #leading><BadgeCheck class="h-4 w-4" /></template>
                        We have received it
                    </UiButton>
                </div>
            </UiCard>

            <UiCard v-if="handover.repository">
                <h2 class="text-base font-semibold">Where the code is</h2>
                <p class="mt-2 whitespace-pre-line text-sm">{{ handover.repository }}</p>
            </UiCard>
            <UiCard v-if="handover.deploymentGuide">
                <h2 class="text-base font-semibold">Deploying it</h2>
                <div class="mt-2"><MarkdownHtml :html="handover.deploymentGuide" /></div>
            </UiCard>
            <UiCard v-if="handover.environmentNotes">
                <h2 class="text-base font-semibold">Environments</h2>
                <div class="mt-2"><MarkdownHtml :html="handover.environmentNotes" /></div>
            </UiCard>
            <UiCard v-if="handover.credentialsNote">
                <h2 class="text-base font-semibold">Access</h2>
                <p class="mt-2 whitespace-pre-line text-sm">{{ handover.credentialsNote }}</p>
            </UiCard>
            <UiCard v-if="handover.licences.length || handover.videos.length">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div v-if="handover.licences.length">
                        <h2 class="text-base font-semibold">Licences</h2>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            <li v-for="(licence, index) in handover.licences" :key="index"><span class="font-medium">{{ licence.name }}</span><span v-if="licence.detail" style="color: var(--text-muted)"> · {{ licence.detail }}</span></li>
                        </ul>
                    </div>
                    <div v-if="handover.videos.length">
                        <h2 class="text-base font-semibold">Training</h2>
                        <ul class="mt-2 space-y-1.5 text-sm">
                            <li v-for="(video, index) in handover.videos" :key="index">
                                <a :href="video.url" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    <PlayCircle class="h-4 w-4" aria-hidden="true" />{{ video.title }}<ExternalLink class="h-3 w-3" aria-hidden="true" />
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </UiCard>

            <!-- ------------------------------------------- survey (CL18) -->
            <UiCard v-if="handover.acknowledged && !surveyAnswered">
                <h2 class="text-base font-semibold">How was working with us?</h2>
                <p class="mt-1 text-sm" style="color: var(--text-muted)">Two minutes. It shapes how we work on the next project.</p>
                <form class="mt-4 space-y-4" @submit.prevent="survey.post(`${base}/survey`, { preserveScroll: true })">
                    <UiFormField label="Overall" required :error="survey.errors.rating">
                        <div class="flex gap-2">
                            <button
                                v-for="score in [1, 2, 3, 4, 5]"
                                :key="score"
                                type="button"
                                class="h-10 w-10 rounded-lg border text-sm font-semibold transition"
                                :style="{ borderColor: survey.rating === score ? 'var(--color-brand-500)' : 'var(--border-subtle)', background: survey.rating === score ? 'var(--color-brand-500)' : 'transparent', color: survey.rating === score ? '#fff' : 'var(--text-base)' }"
                                :aria-pressed="survey.rating === score"
                                :aria-label="`${score} out of 5`"
                                @click="survey.rating = score"
                            >{{ score }}</button>
                        </div>
                    </UiFormField>
                    <UiFormField label="What went well" :error="survey.errors.went_well"><UiTextarea v-model="survey.went_well" :rows="3" /></UiFormField>
                    <UiFormField label="What could have been better" :error="survey.errors.could_improve"><UiTextarea v-model="survey.could_improve" :rows="3" /></UiFormField>
                    <UiFormField label="In a sentence or two, for others thinking of working with us" hint="Optional." :error="survey.errors.quote"><UiTextarea v-model="survey.quote" :rows="2" maxlength="600" /></UiFormField>
                    <template v-if="survey.quote.trim()">
                        <UiCheckbox v-model="survey.consent_to_publish" label="You may publish this quote on your website, credited as I say below." />
                        <UiFormField v-if="survey.consent_to_publish" label="Credit it as" required :error="survey.errors.credit_as">
                            <UiInput v-model="survey.credit_as" maxlength="160" placeholder="Meera Iyer, Head of operations, Meridian Logistics" />
                        </UiFormField>
                    </template>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="survey.processing" :disabled="!survey.rating">Send</UiButton>
                    </div>
                </form>
            </UiCard>
            <p v-else-if="surveyAnswered" class="text-center text-sm" style="color: var(--text-muted)">Thank you for your answers.</p>
        </div>
    </AppLayout>
</template>
