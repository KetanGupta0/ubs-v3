<script setup>
/**
 * A product page.
 *
 * This is where the catalogue has to do its real work: explain what the thing
 * is, what is in it, what it costs roughly, and give the visitor somewhere to
 * ask about it without leaving the page.
 */
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft, Check, Clock, IndianRupee, Layers, Plug, Smartphone,
    Target, KeyRound, ChevronDown,
} from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import MockScreen from '@/components/Marketing/MockScreen.vue';
import SolutionCard from '@/components/Marketing/SolutionCard.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import EnquiryForm from '@/components/Marketing/EnquiryForm.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { accentFor } from '@/support/accents';

const props = defineProps({
    solution: { type: Object, required: true },
    related: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const tone = computed(() => accentFor(props.solution.accent));

/** Feature tour: one expanded at a time, so the page stays scannable. */
const activeFeature = ref(0);

const paragraphs = computed(() =>
    (props.solution.description ?? '').split('\n\n').filter(Boolean),
);
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <!-- ------------------------------------------------------------ hero -->
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div
                class="pointer-events-none absolute -right-32 -top-40 h-[32rem] w-[32rem] rounded-full opacity-25 blur-3xl"
                :style="{ background: tone.glow }"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-5 flex flex-wrap items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <Link href="/solutions" class="transition hover:text-[var(--text-strong)]">Solutions</Link>
                    <span>/</span>
                    <Link
                        :href="`/solutions?category=${solution.categorySlug}`"
                        class="transition hover:text-[var(--text-strong)]"
                    >
                        {{ solution.category }}
                    </Link>
                </nav>

                <div class="grid items-start gap-10 lg:grid-cols-12">
                    <div class="lg:col-span-6">
                        <p class="text-sm font-semibold uppercase tracking-wide" :style="{ color: tone.solid }">
                            {{ solution.category }}
                        </p>

                        <h1 class="mt-2 text-3xl font-semibold leading-tight sm:text-4xl">{{ solution.title }}</h1>
                        <p class="mt-3 text-lg" style="color: var(--text-base)">{{ solution.tagline }}</p>
                        <p class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">{{ solution.summary }}</p>

                        <!-- The three numbers a buyer is looking for. -->
                        <dl class="mt-7 grid gap-3 sm:grid-cols-3">
                            <div
                                v-for="fact in [
                                    { icon: Layers, label: 'Modules', value: `${solution.modules.length}` },
                                    { icon: Clock, label: 'Typical build', value: solution.timeline },
                                    { icon: IndianRupee, label: 'Indicative budget', value: solution.priceBand },
                                ].filter((f) => f.value)"
                                :key="fact.label"
                                class="rounded-xl border p-3.5"
                                :style="{ borderColor: 'var(--border-subtle)', background: tone.softer }"
                            >
                                <dt class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                                    <component :is="fact.icon" class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ fact.label }}
                                </dt>
                                <dd class="mt-1 text-sm font-semibold tnum" style="color: var(--text-strong)">
                                    {{ fact.value }}
                                </dd>
                            </div>
                        </dl>

                        <p class="mt-3 text-xs" style="color: var(--text-muted)">
                            Indicative only. A real figure comes after a short discovery call and
                            arrives as a written proposal with the scope stated.
                        </p>

                        <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                            <UiButton size="lg" href="#enquire" :inertia="false">Ask about this</UiButton>
                            <UiButton size="lg" variant="secondary" href="/solutions">
                                <template #leading><ArrowLeft class="h-4 w-4" /></template>
                                Back to catalogue
                            </UiButton>
                        </div>
                    </div>

                    <div class="lg:col-span-6">
                        <MockScreen
                            :slug="solution.slug"
                            :accent="solution.accent"
                            :modules="solution.modules"
                        />
                        <p class="mt-3 text-center text-xs" style="color: var(--text-muted)">
                            Illustrative interface, not a screenshot of a live client system.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- --------------------------------------------------------- overview -->
        <section v-if="paragraphs.length" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-12">
                <div class="lg:col-span-7">
                    <h2 class="text-2xl font-semibold">Why this exists</h2>
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

                <aside class="space-y-6 lg:col-span-5">
                    <div v-if="solution.outcomes?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Target class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            What changes once it is running
                        </h3>
                        <ul class="mt-3 space-y-2.5">
                            <li
                                v-for="outcome in solution.outcomes"
                                :key="outcome"
                                class="flex items-start gap-2.5 text-sm"
                                style="color: var(--text-base)"
                            >
                                <Check class="mt-0.5 h-4 w-4 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                {{ outcome }}
                            </li>
                        </ul>
                    </div>

                    <div v-if="solution.industries?.length">
                        <h3 class="text-sm font-semibold">Built for</h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <Link
                                v-for="industry in solution.industries"
                                :key="industry"
                                :href="`/solutions?industry=${encodeURIComponent(industry)}`"
                            >
                                <UiBadge size="sm">{{ industry }}</UiBadge>
                            </Link>
                        </div>
                    </div>

                    <div v-if="solution.platforms?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Smartphone class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            Runs on
                        </h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <UiBadge v-for="platform in solution.platforms" :key="platform" size="sm">
                                {{ platform }}
                            </UiBadge>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <!-- ---------------------------------------------------- feature tour -->
        <section v-if="solution.features?.length" class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Feature tour" title="What it actually does" />

                <div class="mt-8 grid gap-4 lg:grid-cols-12">
                    <!-- Desktop: a list that drives the panel beside it. -->
                    <ul class="hidden space-y-1.5 lg:col-span-5 lg:block">
                        <li v-for="(feature, index) in solution.features" :key="feature.title">
                            <button
                                type="button"
                                :class="[
                                    'w-full rounded-xl border p-4 text-left transition-[background-color,border-color,transform] duration-[var(--duration-base)]',
                                    activeFeature === index ? 'translate-x-1' : 'hover:translate-x-0.5',
                                ]"
                                :style="activeFeature === index
                                    ? { borderColor: tone.border, background: 'var(--surface)' }
                                    : { borderColor: 'transparent' }"
                                :aria-expanded="activeFeature === index"
                                @click="activeFeature = index"
                            >
                                <span class="flex items-center gap-2.5">
                                    <span
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs font-semibold tnum"
                                        :style="activeFeature === index
                                            ? { background: tone.gradient, color: '#fff' }
                                            : { background: 'var(--surface)', color: 'var(--text-muted)' }"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    <span class="text-sm font-semibold" style="color: var(--text-strong)">
                                        {{ feature.title }}
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>

                    <div class="hidden lg:col-span-7 lg:block">
                        <div
                            class="sticky top-24 rounded-[var(--radius-card)] border p-7"
                            style="border-color: var(--border-subtle); background: var(--surface)"
                        >
                            <Transition
                                mode="out-in"
                                enter-active-class="transition duration-[var(--duration-base)] ease-[var(--ease-out-expo)]"
                                leave-active-class="transition duration-100"
                                enter-from-class="opacity-0 translate-y-2"
                                leave-to-class="opacity-0"
                            >
                                <div :key="activeFeature">
                                    <span
                                        class="inline-flex h-10 w-10 items-center justify-center rounded-xl text-sm font-semibold text-white tnum"
                                        :style="{ background: tone.gradient }"
                                    >
                                        {{ activeFeature + 1 }}
                                    </span>
                                    <h3 class="mt-4 text-xl font-semibold">
                                        {{ solution.features[activeFeature].title }}
                                    </h3>
                                    <p class="mt-2 text-base leading-relaxed" style="color: var(--text-muted)">
                                        {{ solution.features[activeFeature].body }}
                                    </p>
                                </div>
                            </Transition>
                        </div>
                    </div>

                    <!-- Phone: an accordion, since a two column tour does not fit. -->
                    <ul class="space-y-2 lg:hidden">
                        <li
                            v-for="(feature, index) in solution.features"
                            :key="feature.title"
                            class="overflow-hidden rounded-xl border"
                            style="border-color: var(--border-subtle); background: var(--surface)"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-3 p-4 text-left"
                                :aria-expanded="activeFeature === index"
                                @click="activeFeature = activeFeature === index ? -1 : index"
                            >
                                <span class="flex items-center gap-2.5">
                                    <span
                                        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-xs font-semibold text-white tnum"
                                        :style="{ background: tone.gradient }"
                                    >
                                        {{ index + 1 }}
                                    </span>
                                    <span class="text-sm font-semibold">{{ feature.title }}</span>
                                </span>
                                <ChevronDown
                                    class="h-4 w-4 shrink-0 transition-transform"
                                    :class="activeFeature === index && 'rotate-180'"
                                    style="color: var(--text-muted)"
                                />
                            </button>

                            <p
                                v-if="activeFeature === index"
                                class="px-4 pb-4 text-sm leading-relaxed"
                                style="color: var(--text-muted)"
                            >
                                {{ feature.body }}
                            </p>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- ------------------------------------------ modules and integrations -->
        <section class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-12">
                <div v-if="solution.modules?.length" class="lg:col-span-7">
                    <h2 class="text-2xl font-semibold">What is in it</h2>
                    <p class="mt-2 text-sm" style="color: var(--text-muted)">
                        We build the modules you need and leave out the ones you do not. Nobody
                        pays for something they will never open.
                    </p>

                    <ul class="mt-5 grid gap-2.5 sm:grid-cols-2">
                        <li
                            v-for="(module, index) in solution.modules"
                            :key="module"
                            class="animate-fade-up flex items-center gap-2.5 rounded-xl border p-3.5"
                            :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 40}ms` }"
                        >
                            <span
                                class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                                :style="{ background: tone.soft, color: tone.solid }"
                            >
                                <Layers class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <span class="text-sm font-medium" style="color: var(--text-base)">{{ module }}</span>
                        </li>
                    </ul>
                </div>

                <aside class="space-y-7 lg:col-span-5">
                    <div v-if="solution.techStack?.length">
                        <h3 class="text-sm font-semibold">Built with</h3>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <Link
                                v-for="tech in solution.techStack"
                                :key="tech"
                                :href="`/solutions?tech=${encodeURIComponent(tech)}`"
                            >
                                <UiBadge size="sm">{{ tech }}</UiBadge>
                            </Link>
                        </div>
                    </div>

                    <div v-if="solution.integrations?.length">
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <Plug class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            Connects to
                        </h3>
                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="integration in solution.integrations"
                                :key="integration"
                                class="flex items-center gap-2 text-sm"
                                style="color: var(--text-base)"
                            >
                                <Check class="h-3.5 w-3.5 shrink-0" :style="{ color: tone.solid }" aria-hidden="true" />
                                {{ integration }}
                            </li>
                        </ul>
                    </div>

                    <div
                        v-if="solution.needsApiKeys"
                        class="rounded-xl border p-4"
                        :style="{ borderColor: tone.border, background: tone.softer }"
                    >
                        <h3 class="flex items-center gap-2 text-sm font-semibold">
                            <KeyRound class="h-4 w-4" :style="{ color: tone.solid }" aria-hidden="true" />
                            Needs API keys
                        </h3>
                        <p class="mt-1.5 text-sm" style="color: var(--text-muted)">
                            This product talks to outside services. You can buy and manage those
                            keys from your client dashboard, with usage and quota visible.
                        </p>
                    </div>
                </aside>
            </div>
        </section>

        <!-- -------------------------------------------------------- enquiry -->
        <section id="enquire" class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    align="center"
                    eyebrow="Ask about this"
                    title="Tell us how your version would differ"
                    body="Almost nobody wants exactly what is described above, and that is fine. Describe the part that is different and we will tell you what it changes."
                />

                <div class="mt-8">
                    <EnquiryForm
                        interest="solution"
                        :solution-slug="solution.slug"
                        :context-label="solution.title"
                        show-project-fields
                    />
                </div>
            </div>
        </section>

        <!-- --------------------------------------------------------- related -->
        <section v-if="related.length" class="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading
                eyebrow="Related"
                :title="`More ${solution.category.toLowerCase()} software`"
            />

            <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <SolutionCard
                    v-for="(item, index) in related"
                    :key="item.slug"
                    :solution="item"
                    :delay="index * 70"
                />
            </div>
        </section>
    </PublicLayout>
</template>
