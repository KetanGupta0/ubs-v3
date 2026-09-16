<script setup>
/**
 * Public training listing.
 *
 * Only publicly visible programmes reach this page. Anything marked as existing
 * inside the learning management system only is excluded by the server.
 */
import { computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowUpRight, CalendarDays, Clock, Users, Video, X } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { accentFor } from '@/support/accents';

const props = defineProps({
    courses: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    levels: { type: Array, default: () => [] },
    totalCount: { type: Number, default: 0 },
    seo: { type: Object, required: true },
});

function apply(overrides) {
    const query = { level: props.filters.level, type: props.filters.type, ...overrides };
    Object.keys(query).forEach((key) => !query[key] && delete query[key]);

    router.get('/training', query, { preserveState: true, preserveScroll: true, replace: true });
}

function toggle(key, value) {
    apply({ [key]: props.filters[key] === value ? undefined : value });
}

const hasFilters = computed(() => Boolean(props.filters.level || props.filters.type));

const promises = [
    { icon: Video, title: 'Live, not recorded', body: 'Every session on Google Meet at a fixed time, with a person who can answer you.' },
    { icon: Users, title: 'Small batches', body: 'Capped so that asking a question does not mean competing with two hundred people.' },
    { icon: CalendarDays, title: 'Attendance tracked', body: 'And you hear from us before a shortfall becomes a problem, not afterwards.' },
];
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />
            <div
                class="pointer-events-none absolute -top-40 right-0 h-[28rem] w-[28rem] rounded-full opacity-20 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-accent-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Training</span>
                </nav>

                <h1 class="max-w-3xl text-3xl font-semibold leading-tight sm:text-4xl">
                    Live training, taught by people who ship software
                </h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ totalCount }} programmes, all taught live rather than recorded. Attendance,
                    assessment, a project that gets reviewed, and a certificate an employer can
                    verify.
                </p>

                <div class="mt-8 grid gap-4 sm:grid-cols-3">
                    <div v-for="promise in promises" :key="promise.title" class="flex items-start gap-3">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent-50 text-accent-700 dark:bg-accent-900/40 dark:text-accent-300">
                            <component :is="promise.icon" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ promise.title }}</span>
                            <span class="block text-xs leading-relaxed" style="color: var(--text-muted)">{{ promise.body }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <!-- Filters. Few enough to sit inline rather than behind a drawer. -->
            <div class="mb-7 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Level</span>
                <button
                    v-for="level in levels"
                    :key="level"
                    type="button"
                    :class="[
                        'rounded-full border px-3 py-1.5 text-xs capitalize transition',
                        filters.level === level
                            ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                            : 'text-[var(--text-base)]',
                    ]"
                    :style="filters.level === level ? {} : { borderColor: 'var(--border-subtle)' }"
                    @click="toggle('level', level)"
                >
                    {{ level }}
                </button>

                <span class="ml-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Type</span>
                <button
                    v-for="type in ['course', 'programme']"
                    :key="type"
                    type="button"
                    :class="[
                        'rounded-full border px-3 py-1.5 text-xs capitalize transition',
                        filters.type === type
                            ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                            : 'text-[var(--text-base)]',
                    ]"
                    :style="filters.type === type ? {} : { borderColor: 'var(--border-subtle)' }"
                    @click="toggle('type', type)"
                >
                    {{ type }}
                </button>

                <button
                    v-if="hasFilters"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                    @click="router.get('/training', {}, { preserveScroll: true, replace: true })"
                >
                    <X class="h-3 w-3" /> Clear
                </button>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <Link
                    v-for="(course, index) in courses"
                    :key="course.slug"
                    :href="`/training/${course.slug}`"
                    class="animate-fade-up group relative flex flex-col overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)] p-6 shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-[var(--duration-base)] hover:-translate-y-1 hover:shadow-[var(--shadow-pop)] sm:p-7"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                >
                    <span
                        class="pointer-events-none absolute inset-x-0 top-0 h-1"
                        :style="{ background: accentFor(course.accent).gradient }"
                        aria-hidden="true"
                    />

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex flex-wrap gap-1.5">
                            <UiBadge tone="accent" size="sm">{{ course.type }}</UiBadge>
                            <UiBadge size="sm" class="capitalize">{{ course.level }}</UiBadge>
                            <UiBadge v-if="course.featured" tone="brand" size="sm">Popular</UiBadge>
                        </div>

                        <ArrowUpRight
                            class="h-4 w-4 shrink-0 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                            :style="{ color: accentFor(course.accent).solid }"
                        />
                    </div>

                    <h2 class="mt-4 text-xl font-semibold leading-snug">{{ course.title }}</h2>
                    <p class="mt-1.5 text-sm font-medium" style="color: var(--text-base)">{{ course.tagline }}</p>
                    <p class="mt-2.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ course.summary }}</p>

                    <div v-if="course.tools?.length" class="mt-4 flex flex-wrap gap-1.5">
                        <UiBadge v-for="tool in course.tools" :key="tool" size="sm">{{ tool }}</UiBadge>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-3 border-t pt-4 text-sm sm:grid-cols-3" style="border-color: var(--border-subtle)">
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                <Clock class="h-3 w-3" aria-hidden="true" /> Duration
                            </dt>
                            <dd class="mt-0.5 font-medium tnum" style="color: var(--text-strong)">
                                {{ course.durationWeeks }} weeks
                            </dd>
                        </div>

                        <div v-if="course.nextBatch">
                            <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                <CalendarDays class="h-3 w-3" aria-hidden="true" /> Next batch
                            </dt>
                            <dd class="mt-0.5 font-medium" style="color: var(--text-strong)">
                                {{ course.nextBatch.startsOnLabel }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs" style="color: var(--text-muted)">Fee</dt>
                            <dd class="mt-0.5 font-medium tnum" style="color: var(--text-strong)">
                                <template v-if="course.price">
                                    ₹{{ course.price.toLocaleString('en-IN') }}
                                    <span
                                        v-if="course.originalPrice"
                                        class="ml-1 text-xs font-normal line-through"
                                        style="color: var(--text-muted)"
                                    >
                                        ₹{{ course.originalPrice.toLocaleString('en-IN') }}
                                    </span>
                                </template>
                                <template v-else>On request</template>
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="course.nextBatch?.nearlyFull"
                        class="mt-3 text-xs font-medium text-warn-600"
                    >
                        Only {{ course.nextBatch.seatsLeft }} seats left in the next batch
                    </p>
                </Link>
            </div>
        </div>

        <CtaSection
            title="Training a whole team?"
            body="We run private programmes shaped around your own codebase, so the exercises use your domain and the capstone is something you were going to have to build anyway."
            primary-label="Ask about team training"
            secondary-label="See all programmes"
            secondary-href="/training"
        />
    </PublicLayout>
</template>
