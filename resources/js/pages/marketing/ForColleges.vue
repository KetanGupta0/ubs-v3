<script setup>
/**
 * The page a training and placement officer is sent to.
 *
 * Written for someone answerable to a department, not for a student. What they
 * need to know is what paperwork arrives, what reporting they get, and what
 * happens when a student stops turning up.
 */
import { Link } from '@inertiajs/vue3';
import {
    FileSignature, Users, ClipboardList, FileCheck2, CalendarCheck,
    MessageSquare, ArrowRight,
} from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import EnquiryForm from '@/components/Marketing/EnquiryForm.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({
    internships: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const offer = [
    {
        icon: FileSignature,
        title: 'A signed memorandum',
        body: 'Scope, duration, fees, what we deliver and what happens if a student withdraws, written down before anyone enrols.',
    },
    {
        icon: Users,
        title: 'Batch enrolment',
        body: 'Send us the list. Accounts are created in bulk and credentials go to each student by email and SMS.',
    },
    {
        icon: CalendarCheck,
        title: 'Attendance you can see',
        body: 'Session by session, per student, visible to your coordinator rather than requested by email at the end.',
    },
    {
        icon: ClipboardList,
        title: 'Progress reports',
        body: 'Monthly, per student, covering attendance, submissions and mentor assessment, in a form your department can file.',
    },
    {
        icon: FileCheck2,
        title: 'The four documents',
        body: 'Offer letter, completion certificate with online verification, project report and a signed mentor evaluation, for every student.',
    },
    {
        icon: MessageSquare,
        title: 'A named coordinator',
        body: 'One person on our side who knows your batch, rather than a support address.',
    },
];

const honesty = [
    'We do not promise placements, and we would be careful with anyone who does.',
    'We tell you when a student stops attending, early, while it can still be fixed.',
    'A student who does not do the work does not get a completion certificate. That is what makes the certificate worth anything.',
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

            <div class="relative mx-auto max-w-4xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>For colleges</span>
                </nav>

                <UiBadge tone="brand" dot>For training and placement officers</UiBadge>

                <h1 class="mt-5 text-3xl font-semibold leading-tight sm:text-4xl">
                    Send a batch on an internship that produces something
                </h1>

                <p class="mt-5 text-base leading-relaxed" style="color: var(--text-muted)">
                    You need students placed somewhere that meets the curriculum requirement and
                    gives back paperwork your department can file. We need students who will
                    actually do the work. Those are compatible, and this page sets out exactly
                    what each side gets.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading eyebrow="What a tie-up includes" title="What your department gets" />

            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(item, index) in offer"
                    :key="item.title"
                    class="animate-fade-up rounded-[var(--radius-card)] border p-5"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 60}ms` }"
                >
                    <component :is="item.icon" class="h-5 w-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <h3 class="mt-4 text-sm font-semibold">{{ item.title }}</h3>
                    <p class="mt-1.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ item.body }}</p>
                </div>
            </div>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Worth saying before you ask"
                    title="Three things we will not pretend about"
                    body="Each of these costs us something, which is the only reason they are worth stating."
                />

                <ul class="mt-7 space-y-3">
                    <li
                        v-for="(point, index) in honesty"
                        :key="point"
                        class="animate-fade-up rounded-xl border bg-[var(--surface)] p-4 text-sm leading-relaxed"
                        :style="{ borderColor: 'var(--border-subtle)', color: 'var(--text-base)', animationDelay: `${index * 70}ms` }"
                    >
                        {{ point }}
                    </li>
                </ul>
            </div>
        </section>

        <section v-if="internships.length" class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading
                eyebrow="Available tracks"
                title="What a batch can be placed on"
                body="Students in one batch can be split across tracks if your department prefers that."
            />

            <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                <li v-for="item in internships" :key="item.slug">
                    <Link
                        :href="`/internships/${item.slug}`"
                        class="group flex items-center justify-between gap-3 rounded-xl border p-4 transition hover:border-brand-300 dark:hover:border-brand-700"
                        style="border-color: var(--border-subtle)"
                    >
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold">{{ item.title }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">{{ item.durationLabel }}</span>
                        </span>
                        <ArrowRight
                            class="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5"
                            style="color: var(--text-muted)"
                        />
                    </Link>
                </li>
            </ul>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    align="center"
                    eyebrow="Start a conversation"
                    title="Tell us about your batch"
                    body="Which course and year, roughly how many students, and what your curriculum requires. We will send back what a tie-up would look like."
                />

                <div class="mt-8">
                    <EnquiryForm
                        interest="college"
                        context-label="a college tie-up"
                        show-college-fields
                        compact
                    />
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
