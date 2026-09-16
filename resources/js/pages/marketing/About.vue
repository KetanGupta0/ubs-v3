<script setup>
import { Link } from '@inertiajs/vue3';
import { Code2, GraduationCap, Handshake, Eye, Scale } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';

defineProps({ seo: { type: Object, required: true } });

const principles = [
    {
        icon: Eye,
        title: 'Say the inconvenient thing',
        body: 'If a rewrite is cheaper than the work you asked us to quote, we say so, even though it is the smaller job for us. The alternative is being paid to do something we know will not work.',
    },
    {
        icon: Handshake,
        title: 'You should be able to leave',
        body: 'Your code sits in your repository from the first commit, with documentation and a recorded handover. Being hard to replace is not a business model we want.',
    },
    {
        icon: Scale,
        title: 'Scope includes what is out',
        body: 'Every proposal states what is deliberately not included. Nearly every dispute we have seen came from the second list being unwritten rather than the first being wrong.',
    },
];
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
                    A software company that also teaches
                </h1>

                <div class="mt-6 space-y-4 text-base leading-relaxed" style="color: var(--text-muted)">
                    <p>
                        Unboundbyte Solutions Private Limited builds custom software, modernises
                        systems that companies already depend on, and maintains them afterwards. We
                        also run live technical training, taught by the same people who write the
                        software.
                    </p>
                    <p>
                        Those two things are not separate businesses that happen to share an office.
                        Most software fails after delivery, not during it, and it usually fails
                        because the people who inherited it were never taught to run it. Teaching is
                        the other half of building.
                    </p>
                    <p>
                        It works the other way too. Teaching something forces you to understand it
                        well enough to explain it, which is a higher standard than understanding it
                        well enough to make it work.
                    </p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading eyebrow="What we do" title="Two lines of work" />

            <div class="mt-8 grid gap-5 sm:grid-cols-2">
                <div class="rounded-[var(--radius-card)] border p-6" style="border-color: var(--border-subtle)">
                    <Code2 class="h-5 w-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <h3 class="mt-4 text-lg font-semibold">Software development and maintenance</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        New systems, upgrades to existing ones, and annual contracts covering
                        monitoring, patching, backups and a response time we can be held to.
                    </p>
                    <Link href="/services" class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        See what that involves
                    </Link>
                </div>

                <div class="rounded-[var(--radius-card)] border p-6" style="border-color: var(--border-subtle)">
                    <GraduationCap class="h-5 w-5 text-accent-600 dark:text-accent-400" aria-hidden="true" />
                    <h3 class="mt-4 text-lg font-semibold">Live technical training</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">
                        Programmes taught live on Google Meet, with attendance, assessment, a
                        reviewed project and a certificate that can be verified online.
                    </p>
                    <Link href="/training" class="mt-4 inline-block text-sm font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        See the programmes
                    </Link>
                </div>
            </div>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="How we operate"
                    title="Three things we hold to"
                    body="Not values on a wall. Each one costs us something, which is the only reason they are worth stating."
                />

                <div class="mt-8 space-y-4">
                    <div
                        v-for="(principle, index) in principles"
                        :key="principle.title"
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

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <div
                class="rounded-[var(--radius-card)] border p-6 sm:p-8"
                style="border-color: var(--border-subtle)"
            >
                <h2 class="text-lg font-semibold">Where we are today</h2>
                <p class="mt-3 text-sm leading-relaxed" style="color: var(--text-muted)">
                    We are early. Rather than decorate this site with logos and quotes we have not
                    earned, we have put the work into the catalogue: fifteen products described in
                    enough detail that you can judge whether we understand the problem. That
                    seemed more useful, and more honest, than a wall of stock photographs.
                </p>
                <p class="mt-3 text-sm leading-relaxed" style="color: var(--text-muted)">
                    If you want to know whether we can build something, the fastest route is to
                    describe it and let us answer specifically.
                </p>
            </div>
        </section>

        <CtaSection secondary-label="Browse the catalogue" secondary-href="/solutions" />
    </PublicLayout>
</template>
