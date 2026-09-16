<script setup>
/**
 * Phase 0 landing page.
 *
 * A placeholder that proves the shell, the brand and the motion system work
 * end to end. Phase 2 replaces this with the real marketing site.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ArrowUpRight, Code2, GraduationCap, ShieldCheck, Smartphone } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';

const paths = [
    {
        icon: Code2,
        title: 'Software built and maintained',
        body: 'New products, upgrades to systems you already run, and the maintenance that keeps them running afterwards.',
        cta: 'Start a project',
    },
    {
        icon: GraduationCap,
        title: 'Live training, taught by builders',
        body: 'Programmes run as live classes with attendance, assignments and certification, not recorded videos left to rot.',
        cta: 'See programmes',
    },
];

const pillars = [
    { icon: ShieldCheck, label: 'One sign in', body: 'Password, one time code or Google, with optional two factor.' },
    { icon: Smartphone, label: 'Built for the phone', body: 'Every screen works one handed, and mobile apps follow.' },
    { icon: Code2, label: 'Shared foundation', body: 'One component system behind the site and all three dashboards.' },
];
</script>

<template>
    <Head title="Software development and live training" />

    <PublicLayout>
        <section class="relative overflow-hidden">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-50" aria-hidden="true" />
            <div
                class="pointer-events-none absolute -top-40 left-1/2 h-[30rem] w-[52rem] -translate-x-1/2 rounded-full opacity-25 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-5xl px-4 py-24 text-center sm:px-6 sm:py-32">
                <UiBadge tone="brand" dot class="animate-fade-up">Phase 0 · foundation is live</UiBadge>

                <h1
                    class="animate-fade-up mx-auto mt-6 max-w-3xl text-4xl font-semibold leading-[1.08] sm:text-5xl lg:text-6xl"
                    style="animation-delay: 80ms"
                >
                    We build the software, and we train
                    the people who <span class="text-gradient">run it</span>.
                </h1>

                <p
                    class="animate-fade-up mx-auto mt-6 max-w-2xl text-base sm:text-lg"
                    style="animation-delay: 160ms; color: var(--text-muted)"
                >
                    Unboundbyte Solutions delivers custom software and upgrades existing systems,
                    then teaches teams and students to work with them. Two halves of the same
                    problem, handled by one team.
                </p>

                <div class="animate-fade-up mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row" style="animation-delay: 240ms">
                    <UiButton size="lg" href="#paths" :inertia="false">
                        Explore what we build
                        <template #trailing><ArrowUpRight class="h-4 w-4" /></template>
                    </UiButton>
                    <UiButton size="lg" variant="secondary" href="/design">
                        View the design system
                    </UiButton>
                </div>
            </div>
        </section>

        <section id="paths" class="mx-auto max-w-6xl px-4 pb-20 sm:px-6 lg:px-8">
            <div class="grid gap-5 md:grid-cols-2">
                <UiCard v-for="path in paths" :key="path.title" interactive padding="p-7">
                    <span
                        class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300"
                    >
                        <component :is="path.icon" class="h-5 w-5" aria-hidden="true" />
                    </span>

                    <h2 class="mt-5 text-xl font-semibold">{{ path.title }}</h2>
                    <p class="mt-2 text-sm" style="color: var(--text-muted)">{{ path.body }}</p>

                    <p class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 dark:text-brand-400">
                        {{ path.cta }} <ArrowUpRight class="h-4 w-4" />
                    </p>
                </UiCard>
            </div>

            <div class="mt-5 grid gap-5 sm:grid-cols-3">
                <div
                    v-for="pillar in pillars"
                    :key="pillar.label"
                    class="rounded-[var(--radius-card)] border p-5"
                    style="border-color: var(--border-subtle)"
                >
                    <component :is="pillar.icon" class="h-4.5 w-4.5 text-accent-500" aria-hidden="true" />
                    <h3 class="mt-3 text-sm font-semibold">{{ pillar.label }}</h3>
                    <p class="mt-1 text-sm" style="color: var(--text-muted)">{{ pillar.body }}</p>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
