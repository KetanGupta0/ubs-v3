<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft, CalendarDays, Check, ChevronDown, Clock, GraduationCap,
    Target, Users, Video, Wrench,
} from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import EnquiryForm from '@/components/Marketing/EnquiryForm.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { accentFor } from '@/support/accents';

const props = defineProps({
    course: { type: Object, required: true },
    batches: { type: Array, default: () => [] },
    related: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const tone = computed(() => accentFor(props.course.accent));
const openModule = ref(0);

const paragraphs = computed(() => (props.course.description ?? '').split('\n\n').filter(Boolean));

function scheduleLine(batch) {
    return (batch.schedule ?? []).map((slot) => `${slot.day} ${slot.from}–${slot.to}`).join(' · ');
}
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div
                class="pointer-events-none absolute -right-32 -top-40 h-[30rem] w-[30rem] rounded-full opacity-25 blur-3xl"
                :style="{ background: tone.glow }"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-5 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <Link href="/training" class="transition hover:text-[var(--text-strong)]">Training</Link>
                </nav>

                <div class="grid gap-10 lg:grid-cols-12">
                    <div class="lg:col-span-7">
                        <div class="flex flex-wrap gap-1.5">
                            <UiBadge tone="accent" size="sm">{{ course.type }}</UiBadge>
                            <UiBadge size="sm" class="capitalize">{{ course.level }}</UiBadge>
                        </div>

                        <h1 class="mt-4 text-3xl font-semibold leading-tight sm:text-4xl">{{ course.title }}</h1>
                        <p class="mt-3 text-lg" style="color: var(--text-base)">{{ course.tagline }}</p>
                        <p class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">{{ course.summary }}</p>

                        <dl class="mt-7 flex flex-wrap gap-x-8 gap-y-4">
                            <div v-for="fact in [
                                { icon: Clock, label: 'Duration', value: `${course.durationWeeks} weeks` },
                                { icon: Video, label: 'Commitment', value: `${course.hoursPerWeek} hours a week` },
                                { icon: GraduationCap, label: 'Level', value: course.level },
                            ]" :key="fact.label">
                                <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                    <component :is="fact.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ fact.label }}
                                </dt>
                                <dd class="mt-0.5 text-sm font-semibold capitalize" style="color: var(--text-strong)">
                                    {{ fact.value }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Enrolment card. Sticky on desktop, because the fee and the
                         next date are what people scroll back up to check. -->
                    <div class="lg:col-span-5">
                        <div
                            class="sticky top-24 rounded-[var(--radius-card)] border p-6 shadow-[var(--shadow-card)]"
                            style="border-color: var(--border-subtle); background: var(--surface)"
                        >
                            <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                Programme fee
                            </p>

                            <p class="mt-2 flex items-baseline gap-2">
                                <template v-if="course.price">
                                    <span class="text-3xl font-semibold tnum" style="color: var(--text-strong)">
                                        {{ course.priceLabel }}
                                    </span>
                                    <span
                                        v-if="course.originalPrice"
                                        class="text-base line-through tnum"
                                        style="color: var(--text-muted)"
                                    >
                                        {{ course.originalPriceLabel }}
                                    </span>
                                </template>
                                <span v-else class="text-2xl font-semibold" style="color: var(--text-strong)">
                                    On request
                                </span>
                            </p>

                            <p v-if="course.originalPrice" class="mt-1 text-xs font-medium text-signal-600">
                                Save {{ course.savingLabel }} on this batch
                            </p>

                            <div v-if="batches.length" class="mt-5 space-y-2.5">
                                <p class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                    Upcoming batches
                                </p>

                                <div
                                    v-for="batch in batches"
                                    :key="batch.id"
                                    class="rounded-xl border p-3.5"
                                    :style="{ borderColor: 'var(--border-subtle)' }"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="flex items-center gap-1.5 text-sm font-semibold">
                                                <CalendarDays class="h-3.5 w-3.5 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                                {{ batch.startsOnLabel }}
                                            </p>
                                            <p class="mt-1 text-xs" style="color: var(--text-muted)">
                                                {{ scheduleLine(batch) }}
                                            </p>
                                        </div>

                                        <UiBadge v-if="batch.nearlyFull" tone="warning" size="sm">
                                            {{ batch.seatsLeft }} left
                                        </UiBadge>
                                        <UiBadge v-else-if="batch.seatsLeft !== null" size="sm">
                                            {{ batch.seatsLeft }} seats
                                        </UiBadge>
                                    </div>
                                </div>
                            </div>

                            <UiButton class="mt-5" size="lg" block href="#enquire" :inertia="false">
                                Ask about joining
                            </UiButton>

                            <p class="mt-3 text-center text-xs" style="color: var(--text-muted)">
                                Instalments available on the longer programmes.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="paragraphs.length" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-7">
                    <h2 class="text-2xl font-semibold">What this is</h2>
                    <div class="mt-4 space-y-4">
                        <p
                            v-for="(paragraph, index) in paragraphs"
                            :key="index"
                            class="text-base leading-relaxed"
                            style="color: var(--text-muted)"
                        >
                            {{ paragraph }}
                        </p>
                    </div>
                </div>

                <aside class="space-y-7 lg:col-span-5">
                    <div v-if="course.outcomes?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Target class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            By the end you will be able to
                        </h3>
                        <ul class="mt-3 space-y-2.5">
                            <li
                                v-for="outcome in course.outcomes"
                                :key="outcome"
                                class="flex items-start gap-2.5 text-sm"
                                style="color: var(--text-base)"
                            >
                                <Check class="mt-0.5 h-4 w-4 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                {{ outcome }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="course.audience?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Users class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            Who it is for
                        </h3>
                        <ul class="mt-3 space-y-2 text-sm" style="color: var(--text-base)">
                            <li v-for="item in course.audience" :key="item" class="flex items-start gap-2.5">
                                <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" :style="{ background: tone.solid }" />
                                {{ item }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="course.prerequisites?.length">
                        <h3 class="text-sm font-semibold">What you need before starting</h3>
                        <ul class="mt-3 space-y-2 text-sm" style="color: var(--text-muted)">
                            <li v-for="item in course.prerequisites" :key="item">{{ item }}</li>
                        </ul>
                    </div>

                    <div v-if="course.tools?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Wrench class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            Tools you will use
                        </h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <UiBadge v-for="tool in course.tools" :key="tool" size="sm">{{ tool }}</UiBadge>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <!-- --------------------------------------------------------- syllabus -->
        <section v-if="course.syllabus?.length" class="py-12" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Syllabus" title="Week by week" />

                <ul class="mt-7 space-y-2">
                    <li
                        v-for="(module, index) in course.syllabus"
                        :key="module.module"
                        class="animate-fade-up overflow-hidden rounded-xl border bg-[var(--surface)]"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 60}ms` }"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 p-4 text-left"
                            :aria-expanded="openModule === index"
                            @click="openModule = openModule === index ? -1 : index"
                        >
                            <span class="flex min-w-0 items-center gap-3">
                                <span
                                    class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-semibold text-white tnum"
                                    :style="{ background: tone.gradient }"
                                >
                                    {{ index + 1 }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold">{{ module.module }}</span>
                                    <span class="block text-xs" style="color: var(--text-muted)">Weeks {{ module.weeks }}</span>
                                </span>
                            </span>

                            <ChevronDown
                                class="h-4 w-4 shrink-0 transition-transform"
                                :class="openModule === index && 'rotate-180'"
                                style="color: var(--text-muted)"
                            />
                        </button>

                        <ul v-if="openModule === index" class="space-y-2 px-4 pb-4 pl-[3.75rem]">
                            <li
                                v-for="topic in module.topics"
                                :key="topic"
                                class="flex items-start gap-2.5 text-sm"
                                style="color: var(--text-muted)"
                            >
                                <span class="mt-1.5 h-1 w-1 shrink-0 rounded-full" :style="{ background: tone.solid }" />
                                {{ topic }}
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </section>

        <section id="enquire" class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <SectionHeading
                align="center"
                eyebrow="Join a batch"
                title="Ask us anything before you enrol"
                body="Whether it is the right level for you, whether the timing works, or whether instalments are possible. We would rather answer honestly than fill a seat."
            />

            <div class="mt-8">
                <EnquiryForm
                    interest="training"
                    :course-slug="course.slug"
                    :context-label="course.title"
                />
            </div>
        </section>

        <section v-if="related.length" class="py-12" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Other programmes
                </h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <Link
                        v-for="item in related"
                        :key="item.slug"
                        :href="`/training/${item.slug}`"
                        class="group rounded-xl border bg-[var(--surface)] p-4 transition hover:-translate-y-0.5"
                        style="border-color: var(--border-subtle)"
                    >
                        <UiBadge size="sm" class="capitalize">{{ item.level }}</UiBadge>
                        <h3 class="mt-2.5 text-sm font-semibold leading-snug">{{ item.title }}</h3>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ item.tagline }}</p>
                    </Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
