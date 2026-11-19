<script setup>
/**
 * What the integrity checks found on a quiz (§9.1, layer 4): the flagged
 * attempts, the most worrying first, with the AI's reading where there is
 * one. A flag is a prompt to look, never a verdict; the decision is made on
 * each attempt's own page.
 */
import { Head, router } from '@inertiajs/vue3';
import { ArrowRight, Bot, RefreshCw, ShieldCheck } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    course: { type: Object, required: true },
    quiz: { type: Object, required: true },
    attempts: { type: Array, default: () => [] },
    aiAvailable: { type: Boolean, default: false },
});

function checkNow() {
    router.post(`/admin/courses/${props.course.id}/quizzes/${props.quiz.id}/integrity/check`, {}, { preserveScroll: true });
}

const riskTone = { high: 'danger', medium: 'warning', low: 'brand', none: 'success' };
const riskLabel = { high: 'High', medium: 'Medium', low: 'Low', none: 'Nothing beyond the flags' };

function aiLine(ai) {
    if (!ai) return null;
    if (ai.status === 'queued' || ai.status === 'sent') return ['AI review on its way', 'neutral'];
    if (ai.status === 'failed') return ['AI review did not come back', 'neutral'];

    return [`AI: ${riskLabel[ai.risk] ?? ai.risk}`, riskTone[ai.risk] ?? 'neutral'];
}
</script>

<template>
    <Head :title="`Integrity · ${quiz.title}`" />

    <AppLayout
        :title="`Integrity · ${quiz.title}`"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: course.title, href: `/admin/courses/${course.id}/quizzes` },
            { label: quiz.title },
            { label: 'Integrity' },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader
                :title="quiz.title"
                :description="`Attempts the checks flagged, the most worrying first. ${quiz.submitted} submitted. ${quiz.checkedAt ? `Last checked ${quiz.checkedAt}.` : quiz.closesAt ? `Checked once it closes, ${quiz.closesAt}.` : 'It has no closing time, so it is checked when you ask.'}`"
            >
                <template #actions>
                    <UiButton size="sm" variant="secondary" @click="checkNow">
                        <template #leading><RefreshCw class="h-3.5 w-3.5" /></template>
                        Check now
                    </UiButton>
                </template>
            </PageHeader>

            <p class="rounded-[var(--radius-control)] p-3 text-sm" style="background: var(--surface-sunken)">
                Every flag can be innocent: a notification steals focus, a family shares a router, two good students write the same right answer. Nothing here changes a score or reaches the student until you decide.
                <template v-if="!aiAvailable"> The AI review is off: the Claude API key is not set.</template>
            </p>

            <UiCard v-if="!attempts.length">
                <UiEmptyState :icon="ShieldCheck" title="Nothing flagged" description="When the checks find something worth a look, it is listed here." />
            </UiCard>

            <UiCard v-for="attempt in attempts" :key="attempt.id">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ attempt.student }}
                            <span class="text-xs font-normal" style="color: var(--text-muted)">attempt {{ attempt.number }} · {{ Math.round(attempt.percent) }}% · {{ attempt.submittedAt }}</span>
                            <UiBadge v-if="aiLine(attempt.ai)" :tone="aiLine(attempt.ai)[1]" size="sm">
                                <Bot class="mr-1 h-3 w-3" aria-hidden="true" />
                                {{ aiLine(attempt.ai)[0] }}
                            </UiBadge>
                            <UiBadge v-if="attempt.decision" :tone="attempt.decision.final ? 'neutral' : 'brand'" size="sm" dot>
                                {{ attempt.decision.label }}{{ attempt.decision.final ? '' : attempt.decision.replied ? ' · they replied' : ' · not final' }}
                            </UiBadge>
                            <UiBadge v-if="attempt.voided" tone="warning" size="sm">void</UiBadge>
                        </p>
                        <ul class="mt-2 space-y-1 text-sm" style="color: var(--text-base)">
                            <li v-for="flag in attempt.flags" :key="flag.type + flag.summary">· {{ flag.summary }}</li>
                        </ul>
                        <p v-if="attempt.ai?.summary" class="mt-2 text-sm" style="color: var(--text-muted)">{{ attempt.ai.summary }}</p>
                    </div>
                    <UiButton :href="`/admin/quiz-attempts/${attempt.id}/integrity`" size="sm" :variant="attempt.decision?.final ? 'ghost' : 'secondary'">
                        Look
                        <template #trailing><ArrowRight class="h-3.5 w-3.5" /></template>
                    </UiButton>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
