<script setup>
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Code2, ArrowUpCircle, LifeBuoy, Check } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({
    services: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const icons = { Code2, ArrowUpCircle, LifeBuoy };
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Services</span>
                </nav>

                <h1 class="max-w-3xl text-3xl font-semibold leading-tight sm:text-4xl">
                    Build it, fix what you have, or keep it running
                </h1>
                <p class="mt-4 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    Three different jobs with three different shapes. Each page below states what
                    you get, how we charge for it, and the questions people usually ask before
                    signing anything.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="space-y-5">
                <Link
                    v-for="(service, index) in services"
                    :key="service.slug"
                    :href="`/services/${service.slug}`"
                    class="animate-fade-up group block rounded-[var(--radius-card)] border bg-[var(--surface)] p-6 shadow-[var(--shadow-card)] transition-[transform,box-shadow,border-color] duration-[var(--duration-base)] hover:-translate-y-1 hover:border-brand-300 hover:shadow-[var(--shadow-pop)] sm:p-8 dark:hover:border-brand-700"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 90}ms` }"
                >
                    <div class="grid gap-6 lg:grid-cols-12">
                        <div class="lg:col-span-7">
                            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                                <component :is="icons[service.icon] ?? Code2" class="h-5 w-5" aria-hidden="true" />
                            </span>

                            <h2 class="mt-5 text-xl font-semibold sm:text-2xl">{{ service.title }}</h2>
                            <p class="mt-1.5 text-base font-medium" style="color: var(--text-base)">{{ service.tagline }}</p>
                            <p class="mt-3 text-sm leading-relaxed" style="color: var(--text-muted)">{{ service.summary }}</p>

                            <span class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-600 dark:text-brand-400">
                                Read the detail
                                <ArrowRight class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" />
                            </span>
                        </div>

                        <div class="lg:col-span-5">
                            <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                What you get
                            </h3>
                            <ul class="mt-3 space-y-2">
                                <li
                                    v-for="item in service.deliverables"
                                    :key="item"
                                    class="flex items-start gap-2 text-sm"
                                    style="color: var(--text-base)"
                                >
                                    <Check class="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-500" aria-hidden="true" />
                                    {{ item }}
                                </li>
                            </ul>

                            <div class="mt-4 flex flex-wrap gap-1.5">
                                <UiBadge v-for="model in service.engagementModels" :key="model" size="sm">
                                    {{ model }}
                                </UiBadge>
                            </div>
                        </div>
                    </div>
                </Link>
            </div>
        </section>

        <CtaSection secondary-label="Browse the catalogue" secondary-href="/solutions" />
    </PublicLayout>
</template>
