<script setup>
/**
 * Proctoring (§9.1a): the sittings open now and coming, and every proctored
 * quiz. A proctor starts here and opens the console for the sitting in front
 * of them; somebody who builds courses also reaches each quiz's settings.
 */
import { Head } from '@inertiajs/vue3';
import { Eye, MonitorCheck, Settings2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    slots: { type: Array, default: () => [] },
    quizzes: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const states = {
    open: ['Sitting now', 'success'],
    coming: ['Coming', 'brand'],
    ended: ['Ended', 'neutral'],
};
</script>

<template>
    <Head title="Proctoring" />

    <AppLayout title="Proctoring" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Proctoring' }]">
        <div class="space-y-6">
            <PageHeader
                title="Proctoring"
                description="Proctored quizzes are sat in full screen during a sitting, and the console shows every student sitting one, live: in full screen or not, in the quiz or away, what they tried. You can message a student, give more time or end an attempt. Nobody's screen or camera is seen, and nothing is recorded."
            />

            <section class="space-y-3">
                <h2 class="text-base font-semibold">Sittings</h2>

                <UiCard v-if="!slots.length">
                    <p class="text-sm" style="color: var(--text-muted)">
                        No sittings today or in the next month. Sittings are added on a proctored quiz's settings.
                    </p>
                </UiCard>

                <UiCard v-for="slot in slots" :key="slot.id">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ slot.quiz }}
                                <UiBadge :tone="states[slot.state][1]" size="sm" dot>{{ states[slot.state][0] }}</UiBadge>
                            </p>
                            <p class="mt-0.5 text-sm" style="color: var(--text-muted)">
                                {{ slot.window }} · {{ slot.course }} · {{ slot.batch }}<template v-if="slot.note"> · {{ slot.note }}</template>
                            </p>
                            <p v-if="slot.started" class="mt-1 text-xs" style="color: var(--text-muted)">
                                {{ slot.started }} started<template v-if="slot.sitting">, {{ slot.sitting }} sitting it now</template>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <UiButton :href="`/admin/proctoring/quizzes/${slot.quizId}/console`" size="sm" :variant="slot.state === 'open' ? 'primary' : 'secondary'">
                                <template #leading><MonitorCheck class="h-3.5 w-3.5" /></template>
                                Console
                            </UiButton>
                            <UiButton v-if="canManage" :href="`/admin/proctoring/quizzes/${slot.quizId}`" size="sm" variant="ghost" :aria-label="`Settings for ${slot.quiz}`">
                                <Settings2 class="h-3.5 w-3.5" />
                            </UiButton>
                        </div>
                    </div>
                </UiCard>
            </section>

            <section class="space-y-3">
                <h2 class="text-base font-semibold">Proctored quizzes</h2>

                <UiCard v-if="!quizzes.length">
                    <UiEmptyState
                        :icon="Eye"
                        title="No proctored quizzes yet"
                        description="A quiz becomes proctored from its course's Quizzes page, under Proctoring."
                    />
                </UiCard>

                <UiCard v-for="quiz in quizzes" :key="quiz.id">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                {{ quiz.title }}
                                <UiBadge v-if="quiz.requireSeb" tone="accent" size="sm">Safe Exam Browser</UiBadge>
                            </p>
                            <p class="mt-0.5 text-sm" style="color: var(--text-muted)">
                                {{ quiz.course }}
                                · {{ quiz.comingSittings ? `${quiz.comingSittings} ${quiz.comingSittings === 1 ? 'sitting' : 'sittings'} to come` : 'no sitting to come' }}
                                <template v-if="quiz.maxExits"> · submitted after leaving {{ quiz.maxExits }} {{ quiz.maxExits === 1 ? 'time' : 'times' }}</template>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-1">
                            <UiButton :href="`/admin/proctoring/quizzes/${quiz.id}/console`" size="sm" variant="secondary">
                                <template #leading><MonitorCheck class="h-3.5 w-3.5" /></template>
                                Console
                            </UiButton>
                            <UiButton v-if="canManage" :href="`/admin/proctoring/quizzes/${quiz.id}`" size="sm" variant="ghost">
                                <template #leading><Settings2 class="h-3.5 w-3.5" /></template>
                                Settings
                            </UiButton>
                        </div>
                    </div>
                </UiCard>
            </section>
        </div>
    </AppLayout>
</template>
