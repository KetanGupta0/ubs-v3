<script setup>
/**
 * One flagged attempt, in full (§9.1, layer 4): what the checks found, the
 * AI's reading, the timeline, the answers beside those the checks matched,
 * and the decision. The student reads the outcome and the reason, never the
 * note, and can reply once before it is final.
 */
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Bot, CheckCircle2, Lock, XCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    quiz: { type: Object, required: true },
    attempt: { type: Object, required: true },
    flags: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    answers: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    decision: { type: Object, default: null },
    outcomes: { type: Array, default: () => [] },
    aiAvailable: { type: Boolean, default: false },
    aiPending: { type: Boolean, default: false },
});

const base = `/admin/quiz-attempts/${props.attempt.id}`;
const latest = computed(() => props.reviews.find((review) => review.status === 'done') ?? props.reviews[0] ?? null);
const flaggedQuestions = computed(() => new Set(props.flags.flatMap((flag) => flag.questions ?? [])));

const form = useForm({
    outcome: props.decision?.outcome ?? 'cleared',
    reason: props.decision?.reason ?? '',
    note: props.decision?.note ?? '',
    resit: props.decision?.resit ?? true,
});

const willTell = computed(() => form.outcome !== 'cleared' || props.decision?.told);

function decide() {
    form.post(`${base}/decision`, { preserveScroll: true });
}

function finalise() {
    if (window.confirm('Make this decision final? It cannot change after this.')) router.post(`${base}/decision/final`, {}, { preserveScroll: true });
}

function askAi() {
    router.post(`${base}/ai-review`, {}, { preserveScroll: true });
}

const riskTone = { high: 'danger', medium: 'warning', low: 'brand', none: 'success' };
const riskLabel = { high: 'High', medium: 'Medium', low: 'Low', none: 'Nothing beyond the flags' };

const shown = (value) => (Array.isArray(value) ? value.join(', ') : value ?? '—');
</script>

<template>
    <Head :title="`${attempt.student} · ${quiz.title}`" />

    <AppLayout
        :title="`${attempt.student} · ${quiz.title}`"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: quiz.course, href: `/admin/courses/${quiz.courseId}/quizzes` },
            { label: 'Integrity', href: `/admin/courses/${quiz.courseId}/quizzes/${quiz.id}/integrity` },
            { label: attempt.student },
        ]"
    >
        <div class="space-y-5">
            <PageHeader
                :title="`${attempt.student}, attempt ${attempt.number}`"
                :description="`${quiz.title} · ${attempt.score} of ${attempt.totalMarks} (${Math.round(attempt.percent)}%) · started ${attempt.startedAt}, took ${attempt.took}${attempt.proctored ? ' · proctored' : ''}`"
            >
                <template #actions>
                    <UiBadge v-if="attempt.voided" tone="warning" size="md">Void: counts for nothing</UiBadge>
                </template>
            </PageHeader>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div class="space-y-5">
                    <!-- ------------------------------------------- the flags -->
                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">What the checks found</h2></template>
                        <p v-if="!flags.length" class="text-sm" style="color: var(--text-muted)">No flags. It is here because somebody asked about it.</p>
                        <ul v-else class="space-y-2 text-sm">
                            <li v-for="flag in flags" :key="flag.type + flag.summary">
                                {{ flag.summary }}
                                <span v-if="flag.with" style="color: var(--text-muted)">
                                    With
                                    <a :href="`/admin/quiz-attempts/${flag.with.id}/integrity`" class="underline">{{ flag.with.student }}</a>.
                                </span>
                                <span v-if="flag.questions?.length" style="color: var(--text-muted)">
                                    {{ flag.questions.length === 1 ? 'Question' : 'Questions' }} {{ flag.questions.join(', ') }}.
                                </span>
                            </li>
                        </ul>
                        <p v-if="attempt.endedReason && !flags.some((flag) => flag.type === 'ended')" class="mt-3 text-sm" style="color: var(--text-muted)">{{ attempt.endedReason }}</p>
                    </UiCard>

                    <!-- ------------------------------------------- the answers -->
                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Answers</h2></template>
                        <ol class="divide-y" style="border-color: var(--border-subtle)">
                            <li v-for="row in answers" :key="row.number" class="py-3 first:pt-0 last:pb-0">
                                <p class="flex items-start gap-2 text-sm font-medium">
                                    <span style="color: var(--text-muted)">{{ row.number }}.</span>
                                    <span class="flex-1">{{ row.question }}</span>
                                    <UiBadge v-if="flaggedQuestions.has(row.number)" tone="warning" size="sm">flagged</UiBadge>
                                </p>
                                <p class="mt-1 flex items-center gap-1.5 text-sm">
                                    <component
                                        :is="row.right ? CheckCircle2 : XCircle"
                                        v-if="row.autoMarked && row.answer !== null"
                                        class="h-3.5 w-3.5 shrink-0"
                                        :style="{ color: row.right ? 'var(--text-success)' : 'var(--text-danger)' }"
                                    />
                                    <span class="whitespace-pre-line">{{ shown(row.answer) }}</span>
                                </p>
                                <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                    <template v-if="row.correct">Key: {{ shown(row.correct) }} · </template>
                                    {{ row.seconds ?? 0 }} s on it<template v-if="row.answeredAt"> · answered at {{ row.answeredAt }}</template>
                                </p>
                                <div v-for="other in row.others" :key="other.student" class="mt-2 rounded-[var(--radius-field)] px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                                    <span class="text-xs font-medium" style="color: var(--text-muted)">{{ other.student }}:</span>
                                    <span class="whitespace-pre-line">{{ shown(other.answer) }}</span>
                                </div>
                            </li>
                        </ol>
                    </UiCard>

                    <!-- ------------------------------------------- the timeline -->
                    <UiCard>
                        <template #header>
                            <div>
                                <h2 class="text-base font-semibold">Timeline</h2>
                                <p class="mt-0.5 text-xs" style="color: var(--text-muted)">Minutes from the start. Marked "browser" if the student's browser reported it, which can be missing or made up.</p>
                            </div>
                        </template>
                        <ol class="space-y-1.5 text-sm">
                            <li v-for="event in timeline" :key="event.id" class="flex gap-3">
                                <span class="w-12 shrink-0 tnum" style="color: var(--text-muted)">{{ event.at }}</span>
                                <span class="flex-1">
                                    {{ event.label }}<template v-if="event.question"> (question {{ event.question }})</template><template v-if="event.seconds"> · {{ event.seconds }} s</template><template v-if="event.detail"> · {{ event.detail }}</template>
                                </span>
                                <span v-if="event.fromBrowser" class="text-xs" style="color: var(--text-muted)">browser</span>
                            </li>
                        </ol>
                    </UiCard>
                </div>

                <div class="space-y-5">
                    <!-- ------------------------------------------- the AI -->
                    <UiCard>
                        <template #header>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h2 class="flex items-center gap-2 text-base font-semibold"><Bot class="h-4 w-4" aria-hidden="true" /> AI review</h2>
                                <UiButton v-if="aiAvailable && !aiPending" size="xs" variant="ghost" @click="askAi">{{ reviews.length ? 'Ask again' : 'Ask the AI' }}</UiButton>
                            </div>
                        </template>

                        <p v-if="!aiAvailable && !reviews.length" class="text-sm" style="color: var(--text-muted)">Off: the Claude API key is not set.</p>
                        <p v-else-if="!latest" class="text-sm" style="color: var(--text-muted)">Not asked.</p>
                        <template v-else>
                            <p v-if="latest.status === 'queued' || latest.status === 'sent'" class="text-sm" style="color: var(--text-muted)">On its way; it comes back with the batch, usually within the hour.</p>
                            <p v-else-if="latest.status === 'failed'" class="text-sm" style="color: var(--text-muted)">Did not come back: {{ latest.error }}</p>
                            <template v-else>
                                <UiBadge :tone="riskTone[latest.risk]" size="md">{{ riskLabel[latest.risk] }}</UiBadge>
                                <p class="mt-3 text-sm">{{ latest.summary }}</p>
                                <template v-if="latest.evidence.length">
                                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Evidence</p>
                                    <ul class="mt-1.5 space-y-1.5 text-sm">
                                        <li v-for="item in latest.evidence" :key="item.points_at + item.what">
                                            <span class="font-medium">{{ item.points_at }}:</span> {{ item.what }}
                                        </li>
                                    </ul>
                                </template>
                                <template v-if="latest.explanations.length">
                                    <p class="mt-4 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Innocent readings it weighed</p>
                                    <ul class="mt-1.5 list-disc space-y-1 pl-5 text-sm">
                                        <li v-for="item in latest.explanations" :key="item">{{ item }}</li>
                                    </ul>
                                </template>
                                <p class="mt-4 text-xs" style="color: var(--text-muted)">Advice, from what was sent with nobody named · {{ latest.at }}</p>
                            </template>
                        </template>
                    </UiCard>

                    <!-- ------------------------------------------- the decision -->
                    <UiCard>
                        <template #header><h2 class="text-base font-semibold">Decision</h2></template>

                        <div v-if="decision" class="mb-4 space-y-2 text-sm">
                            <p>
                                <span class="font-medium">{{ decision.label }}</span>
                                <span style="color: var(--text-muted)"> · {{ decision.by }}, {{ decision.at }}</span>
                                <UiBadge v-if="decision.final" size="sm" class="ml-1"><Lock class="mr-1 h-3 w-3" aria-hidden="true" />final</UiBadge>
                            </p>
                            <p v-if="decision.reason" style="color: var(--text-base)">{{ decision.reason }}</p>
                            <p v-if="decision.told && !decision.final && !decision.reply" class="text-xs" style="color: var(--text-muted)">They have been told and can reply once.</p>
                            <div v-if="decision.reply" class="rounded-[var(--radius-field)] px-3 py-2" style="background: var(--surface-sunken)">
                                <p class="text-xs font-medium" style="color: var(--text-muted)">Their reply, {{ decision.repliedAt }}</p>
                                <p class="mt-1 whitespace-pre-line">{{ decision.reply }}</p>
                            </div>
                        </div>

                        <form v-if="!decision?.final" class="space-y-4" @submit.prevent="decide">
                            <UiFormField label="Outcome" :error="form.errors.outcome">
                                <UiSelect v-model="form.outcome" :options="outcomes" />
                            </UiFormField>
                            <UiCheckbox v-if="form.outcome === 'voided'" v-model="form.resit" label="Let them sit it again" />
                            <UiFormField
                                v-if="form.outcome !== 'cleared' || decision?.told"
                                label="Why"
                                hint="The student reads this. Say what was seen, not what you think of them."
                                :error="form.errors.reason"
                            >
                                <UiTextarea v-model="form.reason" :rows="3" maxlength="1000" />
                            </UiFormField>
                            <UiFormField label="Note for us" hint="Never shown to the student." :error="form.errors.note">
                                <UiTextarea v-model="form.note" :rows="2" maxlength="2000" />
                            </UiFormField>
                            <p class="text-xs" style="color: var(--text-muted)">
                                <template v-if="!willTell">Clearing it is final, and nothing is sent: they were never told anything was looked at.</template>
                                <template v-else-if="form.outcome === 'warned'">A warning goes on the next rung of the ladder, which tells them; they can reply here once.</template>
                                <template v-else-if="form.outcome === 'voided'">The attempt stays on the record and counts for nothing, points included.</template>
                                <template v-else>They are told, and can reply once before it is final.</template>
                            </p>
                            <div class="flex flex-wrap justify-end gap-2">
                                <UiButton v-if="decision && decision.told" type="button" variant="ghost" @click="finalise">Make it final</UiButton>
                                <UiButton type="submit" :loading="form.processing">{{ decision ? 'Change the decision' : 'Decide' }}</UiButton>
                            </div>
                        </form>
                    </UiCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
