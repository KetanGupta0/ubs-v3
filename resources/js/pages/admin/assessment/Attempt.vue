<script setup>
/**
 * Marking the written answers in one quiz attempt.
 *
 * The auto marked questions are shown too, read only, because a written answer
 * marked without seeing how the student did on the rest is marked blind.
 */
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Check, X, PenLine, RotateCcw } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';

const props = defineProps({
    attempt: { type: Object, required: true },
    answers: { type: Array, default: () => [] },
    grants: { type: Array, default: () => [] },
    canGrant: { type: Boolean, default: false },
});

/* ------------------------------------------------- extra attempts for them */

const granting = ref(false);
const grantForm = useForm({ extra_attempts: '1', reason: '' });

function openGrant() {
    grantForm.reset();
    grantForm.clearErrors();
    granting.value = true;
}

function grant() {
    grantForm.post(`/admin/marking/attempts/${props.attempt.id}/grant`, {
        preserveScroll: true,
        onSuccess: () => { granting.value = false; },
    });
}

/** One draft per written answer, so marking one does not disturb the others. */
const drafts = reactive(
    Object.fromEntries(
        props.answers
            .filter((answer) => answer.needsMarking)
            .map((answer) => [answer.id, { marks: answer.marks ?? 0, feedback: answer.feedback ?? '' }]),
    ),
);

const saving = reactive({});

function mark(answer) {
    saving[answer.id] = true;

    router.post(`/admin/marking/attempts/${props.attempt.id}/answers/${answer.id}`, drafts[answer.id], {
        preserveScroll: true,
        onFinish: () => { saving[answer.id] = false; },
    });
}

const responseText = (answer) =>
    Array.isArray(answer.response) ? answer.response.join(', ') : (answer.response ?? '—');
</script>

<template>
    <Head :title="`${attempt.student} — ${attempt.quiz}`" />

    <AppLayout
        title="Quiz attempt"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Marking', href: '/admin/marking' },
            { label: attempt.student },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader
                :title="attempt.quiz"
                :description="`${attempt.student} · ${attempt.course} · sat ${attempt.submittedAt}`"
            >
                <template #actions>
                    <UiButton href="/admin/marking" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Queue
                    </UiButton>
                </template>
            </PageHeader>

            <div class="flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                <UiBadge size="sm">{{ attempt.score }} / {{ attempt.totalMarks }}</UiBadge>
                <UiBadge size="sm">{{ attempt.percent }}%</UiBadge>
                <UiBadge v-if="attempt.needsReview" tone="accent" size="sm">
                    <PenLine class="mr-1 h-3 w-3" />written answers waiting
                </UiBadge>
                <span>The total updates as each written answer is marked.</span>
            </div>

            <UiCard padding="p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">Attempts</p>
                        <p class="text-xs" style="color: var(--text-muted)">
                            This is attempt {{ attempt.attemptNumber }}. {{ attempt.student }} has used
                            {{ attempt.attemptsUsed }} of {{ attempt.attemptsAllowed }}<template v-if="!attempt.quizOpen">, and the quiz is closed</template>.
                        </p>
                    </div>
                    <UiButton v-if="canGrant" variant="secondary" size="sm" @click="openGrant">
                        <template #leading><RotateCcw class="h-3.5 w-3.5" /></template>
                        Allow more attempts
                    </UiButton>
                </div>
                <ul v-if="grants.length" class="mt-3 space-y-1.5 text-xs" style="color: var(--text-muted)">
                    <li v-for="item in grants" :key="item.id">
                        +{{ item.extra }} on {{ item.on }}<template v-if="item.by"> by {{ item.by }}</template>: {{ item.reason }}
                    </li>
                </ul>
            </UiCard>

            <UiCard v-for="(answer, index) in answers" :key="answer.id" padding="p-4">
                <p class="text-sm font-medium">{{ index + 1 }}. {{ answer.question }}</p>

                <p class="mt-2 text-xs" style="color: var(--text-muted)">Their answer</p>
                <p class="mt-1 whitespace-pre-line rounded-[var(--radius-control)] p-3 text-sm" style="background: var(--surface-sunken)">
                    {{ responseText(answer) }}
                </p>

                <!-- Auto marked: shown, not editable. -->
                <template v-if="!answer.needsMarking">
                    <p class="mt-3 flex flex-wrap items-center gap-2 text-xs" style="color: var(--text-muted)">
                        <component
                            :is="answer.isCorrect ? Check : X"
                            class="h-3.5 w-3.5"
                            :style="{ color: answer.isCorrect ? 'var(--color-signal-500)' : 'var(--color-danger-500)' }"
                        />
                        {{ answer.marks }} of {{ answer.outOf }}
                        <span v-if="answer.correct.length">· expected {{ answer.correct.join(', ') }}</span>
                    </p>
                </template>

                <!-- Written: marked by hand. -->
                <template v-else>
                    <div class="mt-4 grid gap-3 sm:grid-cols-[8rem_1fr]">
                        <UiFormField :label="`Marks / ${answer.outOf}`">
                            <UiInput
                                v-model.number="drafts[answer.id].marks"
                                type="number"
                                min="0"
                                :max="answer.outOf"
                                step="0.5"
                            />
                        </UiFormField>

                        <UiFormField label="Feedback">
                            <UiTextarea v-model="drafts[answer.id].feedback" :rows="3" />
                        </UiFormField>
                    </div>

                    <div class="mt-3 flex justify-end">
                        <UiButton size="sm" :loading="saving[answer.id]" @click="mark(answer)">
                            Save this answer
                        </UiButton>
                    </div>
                </template>
            </UiCard>
        </div>

        <UiModal
            :open="granting"
            title="Allow more attempts"
            :description="`${attempt.student} keeps every attempt they have made; this only lets them make more.`"
            :persistent="grantForm.processing"
            @close="granting = false"
        >
            <form id="grant-form" class="space-y-4" @submit.prevent="grant">
                <UiFormField label="Extra attempts" required :error="grantForm.errors.extra_attempts">
                    <UiSelect v-model="grantForm.extra_attempts" :options="['1', '2', '3', '4', '5']" />
                </UiFormField>
                <UiFormField label="Why" required hint="Kept with the grant and in the audit log." :error="grantForm.errors.reason">
                    <UiTextarea v-model="grantForm.reason" :rows="3" placeholder="Power cut during the first attempt" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" :disabled="grantForm.processing" @click="granting = false">Cancel</UiButton>
                <UiButton type="submit" form="grant-form" :loading="grantForm.processing">Allow</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
