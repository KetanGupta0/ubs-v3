<script setup>
/**
 * Internship listing for college students.
 *
 * The documents are stated as prominently as the syllabus. A student here is
 * usually meeting a curriculum requirement as well as trying to learn, and a
 * certificate their department will accept is not a footnote to them.
 */
import { computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import {
    ArrowUpRight, CalendarDays, Clock, FileCheck2, Laptop, UserCheck, X, Building2,
} from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { accentFor } from '@/support/accents';

const props = defineProps({
    internships: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    levels: { type: Array, default: () => [] },
    totalCount: { type: Number, default: 0 },
    seo: { type: Object, required: true },
});

function apply(overrides) {
    const query = { duration: props.filters.duration, level: props.filters.level, ...overrides };
    Object.keys(query).forEach((key) => !query[key] && delete query[key]);

    router.get('/internships', query, { preserveState: true, preserveScroll: true, replace: true });
}

function toggle(key, value) {
    apply({ [key]: props.filters[key] === value ? undefined : value });
}

const hasFilters = computed(() => Boolean(props.filters.duration || props.filters.level));

const durations = [
    { value: 'short', label: 'Up to 6 weeks' },
    { value: 'medium', label: '2 to 3 months' },
    { value: 'long', label: '6 months' },
];

const documents = [
    { icon: FileCheck2, title: 'Offer letter', body: 'Before you start, on letterhead, so you can submit it to your department.' },
    { icon: FileCheck2, title: 'Completion certificate', body: 'With a public verification link your college can check online.' },
    { icon: FileCheck2, title: 'Project report', body: 'Structured the way most universities expect, ready to submit.' },
    { icon: UserCheck, title: 'Mentor evaluation', body: 'Signed by the engineer who reviewed your work, with marks.' },
];
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />
            <div
                class="pointer-events-none absolute -top-40 right-0 h-[28rem] w-[28rem] rounded-full opacity-20 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Internships</span>
                </nav>

                <h1 class="max-w-3xl text-3xl font-semibold leading-tight sm:text-4xl">
                    Internships where you actually build something
                </h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ totalCount }} remote internships for college students. You get a brief rather
                    than a tutorial, a mentor who reviews your code every week, and a project you
                    can defend at a viva or an interview.
                </p>

                <!-- Stated up front, because for a student on a deadline this is
                     the first thing they need to know. -->
                <div
                    class="mt-8 rounded-[var(--radius-card)] border p-5 sm:p-6"
                    style="border-color: var(--border-subtle); background: var(--surface-sunken)"
                >
                    <h2 class="text-sm font-semibold">Every internship produces the four documents your college asks for</h2>

                    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="doc in documents" :key="doc.title" class="flex items-start gap-2.5">
                            <component :is="doc.icon" class="mt-0.5 h-4 w-4 shrink-0 text-signal-500" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ doc.title }}</span>
                                <span class="block text-xs leading-relaxed" style="color: var(--text-muted)">{{ doc.body }}</span>
                            </span>
                        </div>
                    </div>

                    <p class="mt-4 text-xs" style="color: var(--text-muted)">
                        If your department needs something on its own format, send it to us before
                        you start and we will fill it in.
                    </p>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="mb-7 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Duration</span>
                <button
                    v-for="option in durations"
                    :key="option.value"
                    type="button"
                    :class="[
                        'rounded-full border px-3 py-1.5 text-xs transition',
                        filters.duration === option.value
                            ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                            : 'text-[var(--text-base)]',
                    ]"
                    :style="filters.duration === option.value ? {} : { borderColor: 'var(--border-subtle)' }"
                    @click="toggle('duration', option.value)"
                >
                    {{ option.label }}
                </button>

                <span class="ml-3 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Level</span>
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

                <button
                    v-if="hasFilters"
                    type="button"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                    @click="router.get('/internships', {}, { preserveScroll: true, replace: true })"
                >
                    <X class="h-3 w-3" /> Clear
                </button>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <Link
                    v-for="(item, index) in internships"
                    :key="item.slug"
                    :href="`/internships/${item.slug}`"
                    class="animate-fade-up group relative flex flex-col overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)] p-6 shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-[var(--duration-base)] hover:-translate-y-1 hover:shadow-[var(--shadow-pop)] sm:p-7"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                >
                    <span
                        class="pointer-events-none absolute inset-x-0 top-0 h-1"
                        :style="{ background: accentFor(item.accent).gradient }"
                        aria-hidden="true"
                    />

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex flex-wrap gap-1.5">
                            <UiBadge size="sm" class="capitalize">{{ item.level }}</UiBadge>
                            <UiBadge tone="brand" size="sm">
                                <Laptop class="h-3 w-3" aria-hidden="true" /> {{ item.mode }}
                            </UiBadge>
                            <UiBadge v-if="item.featured" tone="accent" size="sm">Popular</UiBadge>
                        </div>

                        <ArrowUpRight
                            class="h-4 w-4 shrink-0 transition-transform group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                            :style="{ color: accentFor(item.accent).solid }"
                        />
                    </div>

                    <h2 class="mt-4 text-xl font-semibold leading-snug">{{ item.title }}</h2>
                    <p class="mt-1.5 text-sm font-medium" style="color: var(--text-base)">{{ item.tagline }}</p>
                    <p class="mt-2.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ item.summary }}</p>

                    <p
                        v-if="item.projectFocus"
                        class="mt-4 rounded-xl px-3.5 py-2.5 text-sm"
                        style="background: var(--surface-sunken); color: var(--text-base)"
                    >
                        <span class="font-semibold">You build:</span> {{ item.projectFocus }}
                    </p>

                    <div v-if="item.tools?.length" class="mt-4 flex flex-wrap gap-1.5">
                        <UiBadge v-for="tool in item.tools" :key="tool" size="sm">{{ tool }}</UiBadge>
                    </div>

                    <dl class="mt-5 grid grid-cols-2 gap-3 border-t pt-4 text-sm sm:grid-cols-4" style="border-color: var(--border-subtle)">
                        <div>
                            <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                <Clock class="h-3 w-3" aria-hidden="true" /> Duration
                            </dt>
                            <dd class="mt-0.5 font-medium" style="color: var(--text-strong)">{{ item.durationLabel }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs" style="color: var(--text-muted)">Per week</dt>
                            <dd class="mt-0.5 font-medium tnum" style="color: var(--text-strong)">{{ item.hoursPerWeek }} hrs</dd>
                        </div>

                        <div v-if="item.nextBatch">
                            <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                <CalendarDays class="h-3 w-3" aria-hidden="true" /> Next
                            </dt>
                            <dd class="mt-0.5 font-medium" style="color: var(--text-strong)">
                                {{ item.nextBatch.startsOnLabel }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs" style="color: var(--text-muted)">Fee</dt>
                            <dd class="mt-0.5 font-medium tnum" style="color: var(--text-strong)">
                                {{ item.priceLabel }}
                                <span
                                    v-if="item.originalPrice"
                                    class="ml-1 text-xs font-normal line-through"
                                    style="color: var(--text-muted)"
                                >
                                    {{ item.originalPriceLabel }}
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="item.nextBatch?.nearlyFull"
                        class="mt-3 text-xs font-medium text-warn-600"
                    >
                        Only {{ item.nextBatch.seatsLeft }} seats left in the next batch
                    </p>
                </Link>
            </div>

            <!-- The route a training and placement officer needs. -->
            <div
                class="mt-8 flex flex-col items-start gap-4 rounded-[var(--radius-card)] border p-6 sm:flex-row sm:items-center sm:justify-between"
                style="border-color: var(--border-subtle); background: var(--surface-sunken)"
            >
                <div class="flex items-start gap-3">
                    <Building2 class="mt-0.5 h-5 w-5 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <div>
                        <h2 class="text-base font-semibold">Sending a whole batch from your college?</h2>
                        <p class="mt-1 text-sm" style="color: var(--text-muted)">
                            We work with institutions directly, with a signed memorandum, a named
                            coordinator and progress reports your department can use.
                        </p>
                    </div>
                </div>

                <UiButton href="/for-colleges" variant="secondary" class="shrink-0">For colleges</UiButton>
            </div>
        </div>

        <CtaSection
            title="Not sure which one fits your requirement?"
            body="Tell us your year, your branch and what your college has asked for, and we will tell you which internship matches. If none of them do, we will say so."
            primary-label="Ask us"
            secondary-label="See our courses"
            secondary-href="/training"
        />
    </PublicLayout>
</template>
