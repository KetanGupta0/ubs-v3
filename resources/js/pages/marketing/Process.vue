<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Search, PenTool, Hammer, ShieldCheck, Handshake } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import { numbered } from '@/support/copy';

const props = defineProps({
    /** The page's wording, as published in the panel (/admin/copy). */
    copy: { type: Object, required: true },
    seo: { type: Object, required: true },
});

// Five stages, each with its icon from the design and its words from the panel.
const steps = computed(() => numbered(props.copy, 'steps', [Search, PenTool, Hammer, ShieldCheck, Handshake], ['title', 'duration', 'body', 'output']));
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

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">{{ copy['intro.title'] }}</h1>
                <p class="mt-5 text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ copy['intro.body'] }}
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-4 py-14 sm:px-6 lg:px-8">
            <ol class="space-y-0">
                <li
                    v-for="(step, index) in steps"
                    :key="index"
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
                            {{ copy['steps.output_label'] }} {{ step.output }}
                        </p>
                    </div>
                </li>
            </ol>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    :eyebrow="copy['visible.eyebrow']"
                    :title="copy['visible.title']"
                    :body="copy['visible.body']"
                />

                <ul class="mt-7 grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="item in copy['visible.items']"
                        :key="item"
                        class="rounded-xl border bg-[var(--surface)] p-4 text-sm"
                        style="border-color: var(--border-subtle); color: var(--text-base)"
                    >
                        {{ item }}
                    </li>
                </ul>
            </div>
        </section>

        <CtaSection :title="copy['cta.title']" :body="copy['cta.body']" :primary-label="copy['cta.primary']" />
    </PublicLayout>
</template>
