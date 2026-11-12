<script setup>
/**
 * The student's landing screen.
 *
 * Opens with the next class and what is due. Somebody signing in at nine in the
 * evening wants to know what to do tonight, not how many points they have.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    Video, ClipboardList, FileText, ArrowRight, Flame, Trophy, Award,
    Megaphone, AlertTriangle, CheckCircle2, BookOpen, ClipboardPen, PlayCircle,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    courses: { type: Array, default: () => [] },
    nextClass: { type: Object, default: null },
    /** The lesson to go back to, or the next one to start. */
    continue: { type: Object, default: null },
    /** Recent classes they were not at, with the recording up. */
    missedRecordings: { type: Array, default: () => [] },
    dueSoon: { type: Array, default: () => [] },
    announcements: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    unreadWarnings: { type: Number, default: 0 },
    /** Registrations from public forms still open: { confirm, review }. */
    registrations: { type: Object, default: () => ({ confirm: 0, review: 0 }) },
    recentActivity: { type: Array, default: () => [] },
});

const icons = { quiz: ClipboardList, assignment: FileText };
</script>

<template>
    <Head title="Your learning" />

    <AppLayout title="Dashboard">
        <div class="mx-auto max-w-7xl space-y-6">
            <!-- ------------------------------------------------ warnings -->
            <Link
                v-if="unreadWarnings"
                href="/student/warnings"
                class="flex items-center gap-3 rounded-[var(--radius-card)] border px-4 py-3.5 transition hover:shadow-[var(--shadow-card)]"
                style="border-color: var(--color-warn-500); background: var(--surface)"
            >
                <AlertTriangle class="h-5 w-5 shrink-0" style="color: var(--color-warn-600)" />
                <span class="min-w-0 flex-1 text-sm">
                    There {{ unreadWarnings === 1 ? 'is a message' : `are ${unreadWarnings} messages` }}
                    from your trainer waiting for you.
                </span>
                <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
            </Link>

            <!-- ----------------------------- registrations from a form -->
            <Link
                v-if="registrations.confirm || registrations.review"
                href="/student/registrations"
                class="flex items-center gap-3 rounded-[var(--radius-card)] border px-4 py-3.5 transition hover:shadow-[var(--shadow-card)]"
                :style="{ borderColor: registrations.confirm ? 'var(--color-brand-500)' : 'var(--border-subtle)', background: 'var(--surface)' }"
            >
                <ClipboardPen class="h-5 w-5 shrink-0 text-brand-500" />
                <span class="min-w-0 flex-1 text-sm">
                    <template v-if="registrations.confirm">
                        {{ registrations.confirm === 1 ? 'A registration needs' : `${registrations.confirm} registrations need` }}
                        you to confirm it was you.
                    </template>
                    <template v-else>
                        {{ registrations.review === 1 ? 'Your registration is' : `${registrations.review} of your registrations are` }}
                        waiting for the team to review.
                    </template>
                </span>
                <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
            </Link>

            <!-- -------------------------------- where they left off -->
            <Link
                v-if="$props.continue"
                :href="$props.continue.href"
                class="flex items-center gap-4 rounded-[var(--radius-card)] border bg-[var(--surface)] p-5 transition hover:shadow-[var(--shadow-card)]"
                style="border-color: var(--border-subtle)"
            >
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                    <PlayCircle class="h-5 w-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        {{ $props.continue.kind === 'continue' ? 'Pick up where you left off' : 'Up next' }}
                    </span>
                    <span class="mt-1 block truncate text-lg font-semibold">{{ $props.continue.lesson }}</span>
                    <span class="block truncate text-sm" style="color: var(--text-muted)">
                        {{ $props.continue.course }}{{ $props.continue.module ? ` · ${$props.continue.module}` : '' }}{{ $props.continue.lastOpened ? ` · opened ${$props.continue.lastOpened}` : '' }}
                    </span>
                </span>
                <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
            </Link>

            <!-- ---------------------------------------------- next class -->
            <UiCard v-if="nextClass" :padding="'p-5'">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide"
                           style="color: var(--text-muted)">
                            <Video class="h-3.5 w-3.5" />
                            {{ nextClass.live ? 'Class is running now' : 'Your next class' }}
                        </p>
                        <p class="mt-1.5 text-lg font-semibold">{{ nextClass.title }}</p>
                        <p class="text-sm" style="color: var(--text-muted)">
                            {{ nextClass.course }} · {{ nextClass.at }} ({{ nextClass.startsIn }})
                        </p>
                    </div>

                    <UiButton
                        v-if="nextClass.joinable"
                        :href="`/student/classes/${nextClass.id}/join`"
                        :inertia="false"
                        target="_blank"
                        :variant="nextClass.live ? 'primary' : 'secondary'"
                    >
                        <template #leading><Video class="h-4 w-4" /></template>
                        Join on Meet
                    </UiButton>
                    <UiButton v-else href="/student/classes" variant="ghost" size="sm">
                        All classes
                        <template #trailing><ArrowRight class="h-3 w-3" /></template>
                    </UiButton>
                </div>
            </UiCard>

            <!-- ------------------------------- recordings to catch up -->
            <section v-if="missedRecordings.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Catch up on what you missed
                </h2>
                <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="session in missedRecordings" :key="session.id">
                        <a
                            :href="session.url"
                            target="_blank"
                            rel="noopener"
                            class="flex h-full items-start gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition hover:shadow-[var(--shadow-card)]"
                            style="border-color: var(--border-subtle)"
                        >
                            <PlayCircle class="mt-0.5 h-5 w-5 shrink-0 text-brand-500" />
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ session.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ session.course }} · {{ session.at }} · recording</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </section>

            <!-- ------------------------------------------------ due soon -->
            <section v-if="dueSoon.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Due soon
                </h2>

                <ul class="grid gap-3 sm:grid-cols-2">
                    <li v-for="item in dueSoon" :key="`${item.kind}-${item.id}`">
                        <Link
                            :href="item.href"
                            class="flex h-full items-start gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition
                                   hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]"
                            :style="{ borderColor: item.overdue ? 'var(--color-warn-500)' : 'var(--border-subtle)' }"
                        >
                            <span
                                class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                style="background: var(--surface-sunken); color: var(--text-muted)"
                            >
                                <component :is="icons[item.kind] ?? FileText" class="h-4 w-4" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ item.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ item.course }} · due {{ item.dueIn }}
                                </span>
                            </span>

                            <UiBadge v-if="item.overdue" tone="warning" size="sm">late</UiBadge>
                        </Link>
                    </li>
                </ul>
            </section>

            <section
                v-else-if="courses.length"
                class="flex items-center gap-3 rounded-[var(--radius-card)] border px-4 py-3.5"
                style="border-color: var(--border-subtle); background: var(--surface)"
            >
                <CheckCircle2 class="h-5 w-5" style="color: var(--color-signal-500)" />
                <p class="text-sm">Nothing due right now. Good place to be.</p>
            </section>

            <!-- --------------------------------------------------- stats -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Day streak"
                    :value="stats.streak ?? 0"
                    :tone="stats.streak ? 'brand' : 'neutral'"
                    :hint="stats.streak ? 'Keep it going' : 'Do one thing today to start it'"
                />
                <StatTile label="Points" :value="stats.points ?? 0" href="/student/leaderboard" />
                <StatTile label="Certificates" :value="stats.certificates ?? 0" href="/student/certificates" />
                <StatTile
                    label="Fees due"
                    :value="stats.outstanding ?? '₹0.00'"
                    :tone="stats.outstanding && stats.outstanding !== '₹0.00' ? 'warning' : 'neutral'"
                    href="/student/payments"
                />
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- ----------------------------------------- my courses -->
                <div class="lg:col-span-2">
                    <UiCard>
                        <template #header>
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="flex items-center gap-2 text-base font-semibold">
                                    <BookOpen class="h-4 w-4" style="color: var(--text-muted)" />
                                    Your courses
                                </h2>
                                <UiButton href="/student/courses" variant="ghost" size="xs">
                                    See all
                                    <template #trailing><ArrowRight class="h-3 w-3" /></template>
                                </UiButton>
                            </div>
                        </template>

                        <UiEmptyState
                            v-if="!courses.length"
                            :icon="BookOpen"
                            title="Not enrolled on anything yet"
                            description="Browse what is running and join one."
                        >
                            <template #action>
                                <UiButton href="/student/catalogue" size="sm">Browse courses</UiButton>
                            </template>
                        </UiEmptyState>

                        <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                            <li v-for="course in courses" :key="course.id" class="py-3.5 first:pt-0 last:pb-0">
                                <Link :href="`/student/courses/${course.id}`" class="block">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm font-semibold">{{ course.title }}</span>
                                        <span class="flex items-center gap-1.5">
                                            <UiBadge v-if="!course.hasPaid" tone="warning" size="sm">fee due</UiBadge>
                                            <UiBadge v-if="course.type === 'internship'" tone="accent" size="sm">
                                                internship
                                            </UiBadge>
                                            <UiBadge v-if="course.status === 'completed'" tone="success" size="sm">
                                                done
                                            </UiBadge>
                                        </span>
                                    </div>

                                    <div class="mt-2">
                                        <UiProgress :value="course.progress" :label="course.batch || 'Self paced'" size="sm" />
                                    </div>
                                </Link>
                            </li>
                        </ul>
                    </UiCard>
                </div>

                <!-- -------------------------------- announcements, activity -->
                <div class="space-y-5">
                    <UiCard>
                        <template #header>
                            <h2 class="flex items-center gap-2 text-base font-semibold">
                                <Megaphone class="h-4 w-4" style="color: var(--text-muted)" />
                                Announcements
                            </h2>
                        </template>

                        <UiEmptyState v-if="!announcements.length" title="Nothing posted yet" />

                        <ul v-else class="space-y-3">
                            <li v-for="announcement in announcements" :key="announcement.id">
                                <Link href="/student/announcements" class="block">
                                    <p class="flex items-center gap-1.5 text-sm font-semibold">
                                        {{ announcement.title }}
                                        <UiBadge v-if="announcement.pinned" size="sm">pinned</UiBadge>
                                    </p>
                                    <p class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ announcement.body }}</p>
                                    <p class="mt-1 text-[0.7rem]" style="color: var(--text-muted)">{{ announcement.at }}</p>
                                </Link>
                            </li>
                        </ul>
                    </UiCard>

                    <UiCard v-if="recentActivity.length">
                        <template #header>
                            <h2 class="flex items-center gap-2 text-base font-semibold">
                                <Flame class="h-4 w-4" style="color: var(--text-muted)" />
                                What you have been doing
                            </h2>
                        </template>

                        <ol class="space-y-2.5">
                            <li v-for="entry in recentActivity" :key="entry.id" class="flex gap-2.5 text-xs">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" style="background: var(--color-brand-500)" />
                                <span class="min-w-0 flex-1">
                                    <span class="block">{{ entry.text }}</span>
                                    <span class="block" style="color: var(--text-muted)">{{ entry.at }}</span>
                                </span>
                            </li>
                        </ol>
                    </UiCard>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
