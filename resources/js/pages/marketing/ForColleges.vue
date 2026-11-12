<script setup>
/**
 * The page a training and placement officer is sent to.
 *
 * Written for someone answerable to a department, not for a student. What they
 * need to know is what paperwork arrives, what reporting they get, and what
 * happens when a student stops turning up.
 */
import { computed } from 'vue';
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
import { numbered } from '@/support/copy';

const props = defineProps({
    internships: { type: Array, default: () => [] },
    /** The page's wording, as published in the panel (/admin/copy). */
    copy: { type: Object, required: true },
    seo: { type: Object, required: true },
});

// Six cards, each with its icon from the design and its words from the panel.
const offer = computed(() => numbered(props.copy, 'offer', [FileSignature, Users, CalendarCheck, ClipboardList, FileCheck2, MessageSquare]));
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

                <UiBadge tone="brand" dot>{{ copy['intro.badge'] }}</UiBadge>

                <h1 class="mt-5 text-3xl font-semibold leading-tight sm:text-4xl">
                    {{ copy['intro.title'] }}
                </h1>

                <p class="mt-5 text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ copy['intro.body'] }}
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <SectionHeading :eyebrow="copy['offer.eyebrow']" :title="copy['offer.title']" />

            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(item, index) in offer"
                    :key="index"
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
                    :eyebrow="copy['honesty.eyebrow']"
                    :title="copy['honesty.title']"
                    :body="copy['honesty.body']"
                />

                <ul class="mt-7 space-y-3">
                    <li
                        v-for="(point, index) in copy['honesty.items']"
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
                :eyebrow="copy['tracks.eyebrow']"
                :title="copy['tracks.title']"
                :body="copy['tracks.body']"
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
                    :eyebrow="copy['enquiry.eyebrow']"
                    :title="copy['enquiry.title']"
                    :body="copy['enquiry.body']"
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
