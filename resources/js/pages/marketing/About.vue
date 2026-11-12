<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Code2, GraduationCap, Handshake, Eye, Scale } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import PeopleGrid from '@/components/Marketing/PeopleGrid.vue';
import { fill, numbered, paragraphs } from '@/support/copy';

const props = defineProps({
    team: { type: Array, default: () => [] },
    /** The page's wording, as published in the panel (/admin/copy). */
    copy: { type: Object, required: true },
    figures: { type: Object, default: () => ({}) },
    seo: { type: Object, required: true },
});

// The icons stay with the design; the words come from the panel.
const principles = computed(() => numbered(props.copy, 'principles', [Eye, Handshake, Scale]));
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-4xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>About</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">
                    {{ copy['intro.title'] }}
                </h1>

                <div class="mt-6 space-y-4 text-base leading-relaxed" style="color: var(--text-muted)">
                    <p v-for="(paragraph, index) in paragraphs(copy['intro.body'])" :key="index">{{ paragraph }}</p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading :eyebrow="copy['work.eyebrow']" :title="copy['work.title']" />

            <div class="mt-8 grid gap-5 sm:grid-cols-2">
                <div class="rounded-[var(--radius-card)] border p-6" style="border-color: var(--border-subtle)">
                    <Code2 class="h-5 w-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <h3 class="mt-4 text-lg font-semibold">{{ copy['work.software_title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        {{ copy['work.software_body'] }}
                    </p>
                    <Link href="/services" class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        {{ copy['work.software_link'] }}
                    </Link>
                </div>

                <div class="rounded-[var(--radius-card)] border p-6" style="border-color: var(--border-subtle)">
                    <GraduationCap class="h-5 w-5 text-accent-600 dark:text-accent-400" aria-hidden="true" />
                    <h3 class="mt-4 text-lg font-semibold">{{ copy['work.training_title'] }}</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        {{ copy['work.training_body'] }}
                    </p>
                    <Link href="/training" class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        {{ copy['work.training_link'] }}
                    </Link>
                </div>
            </div>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    :eyebrow="copy['principles.eyebrow']"
                    :title="copy['principles.title']"
                    :body="copy['principles.body']"
                />

                <div class="mt-8 space-y-4">
                    <div
                        v-for="(principle, index) in principles"
                        :key="index"
                        class="animate-fade-up flex gap-4 rounded-[var(--radius-card)] border bg-[var(--surface)] p-5 sm:p-6"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 80}ms` }"
                    >
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                            <component :is="principle.icon" class="h-4.5 w-4.5" aria-hidden="true" />
                        </span>

                        <div class="min-w-0">
                            <h3 class="text-base font-semibold">{{ principle.title }}</h3>
                            <p class="mt-1.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ principle.body }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <PeopleGrid
            :people="team"
            width="max-w-4xl"
            :eyebrow="copy['team.eyebrow']"
            :title="copy['team.title']"
            :body="copy['team.body']"
        />

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <div
                class="rounded-[var(--radius-card)] border p-6 sm:p-8"
                style="border-color: var(--border-subtle)"
            >
                <h2 class="text-lg font-semibold">{{ copy['today.title'] }}</h2>
                <p
                    v-for="(paragraph, index) in paragraphs(fill(copy['today.body'], figures))"
                    :key="index"
                    class="mt-3 text-sm leading-relaxed"
                    style="color: var(--text-muted)"
                >
                    {{ paragraph }}
                </p>
            </div>
        </section>

        <CtaSection
            :title="copy['cta.title']"
            :body="copy['cta.body']"
            :primary-label="copy['cta.primary']"
            :secondary-label="copy['cta.secondary']"
            secondary-href="/solutions"
        />
    </PublicLayout>
</template>
