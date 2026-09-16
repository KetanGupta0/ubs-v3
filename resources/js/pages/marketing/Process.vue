<script setup>
import { Link } from '@inertiajs/vue3';
import { Search, PenTool, Hammer, ShieldCheck, Handshake } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';

defineProps({ seo: { type: Object, required: true } });

const steps = [
    {
        icon: Search,
        title: 'Understand',
        duration: '1 to 2 weeks',
        body: 'We sit with the people who will use the thing, and separately with whoever currently patches the gaps with a spreadsheet. The second conversation is usually the more useful one.',
        output: 'A written scope, including what is deliberately out',
    },
    {
        icon: PenTool,
        title: 'Shape',
        duration: '1 to 3 weeks',
        body: 'A prototype you can click through before any real code exists. Changing a screen at this point costs an hour. Changing it after the build costs a week.',
        output: 'A clickable prototype of the main screens',
    },
    {
        icon: Hammer,
        title: 'Build',
        duration: 'the bulk of the project',
        body: 'Two week increments, each one deployed somewhere you can open it. You are never waiting six months to find out whether we understood you.',
        output: 'Working software you can use, every two weeks',
    },
    {
        icon: ShieldCheck,
        title: 'Harden',
        duration: '2 to 4 weeks',
        body: 'Load, security, backups and the failure cases. The unglamorous part that decides whether the system survives its first bad day.',
        output: 'A tested restore, a security pass and monitoring',
    },
    {
        icon: Handshake,
        title: 'Hand over',
        duration: '1 week, then 90 days of support',
        body: 'Training, written documentation and a recorded walkthrough. You should be able to replace us without drama, and we would rather you stayed because you want to.',
        output: 'Documentation, access and a team that knows the system',
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
                    <span>Process</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">How a project actually runs</h1>
                <p class="mt-5 text-base leading-relaxed" style="color: var(--text-muted)">
                    Five stages. The important property is that you see working software early and
                    often, so a misunderstanding costs two weeks instead of six months.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <ol class="space-y-0">
                <li
                    v-for="(step, index) in steps"
                    :key="step.title"
                    class="animate-fade-up relative flex gap-5 pb-10 last:pb-0"
                    :style="{ animationDelay: `${index * 90}ms` }"
                >
                    <span
                        v-if="index < steps.length - 1"
                        class="absolute left-[1.375rem] top-12 bottom-0 w-px"
                        style="background: var(--border-subtle)"
                        aria-hidden="true"
                    />

                    <span class="relative z-10 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white">
                        <component :is="step.icon" class="h-5 w-5" aria-hidden="true" />
                    </span>

                    <div class="min-w-0 pt-1">
                        <div class="flex flex-wrap items-baseline gap-x-3">
                            <h2 class="text-lg font-semibold">{{ index + 1 }}. {{ step.title }}</h2>
                            <span class="text-xs" style="color: var(--text-muted)">{{ step.duration }}</span>
                        </div>

                        <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">{{ step.body }}</p>

                        <p
                            class="mt-3 inline-block rounded-lg px-3 py-1.5 text-xs font-medium"
                            style="background: var(--surface-sunken); color: var(--text-base)"
                        >
                            You end up with: {{ step.output }}
                        </p>
                    </div>
                </li>
            </ol>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="While it runs"
                    title="What you can see at any time"
                    body="Once your client dashboard is live you do not have to ask anyone for a status update."
                />

                <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="item in [
                            'Milestones and the current progress against them',
                            'Every document shared on the engagement, versioned',
                            'Proposals and quotations, with your decision recorded',
                            'Invoices, payments and receipts you can print',
                            'Support tickets and the clock against the agreed response time',
                            'A direct chat thread with the team on your project',
                        ]"
                        :key="item"
                        class="rounded-xl border bg-[var(--surface)] p-4 text-sm"
                        style="border-color: var(--border-subtle); color: var(--text-base)"
                    >
                        {{ item }}
                    </li>
                </ul>
            </div>
        </section>

        <CtaSection />
    </PublicLayout>
</template>
