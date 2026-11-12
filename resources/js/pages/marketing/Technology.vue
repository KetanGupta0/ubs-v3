<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Server, Layout, Database, Cloud, ShieldCheck, TestTube2 } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import { numbered, paragraphs } from '@/support/copy';

const props = defineProps({
    /** The page's wording, as published in the panel (/admin/copy). */
    copy: { type: Object, required: true },
    seo: { type: Object, required: true },
});

// Six cards, each with its icon from the design and its words and tools from the panel.
const stacks = computed(() => numbered(props.copy, 'stacks', [Server, Layout, Database, Cloud, TestTube2, ShieldCheck], ['title', 'body', 'items']));
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
                    <span>Technology</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">
                    {{ copy['intro.title'] }}
                </h1>

                <div class="mt-6 space-y-4 text-base leading-relaxed" style="color: var(--text-muted)">
                    <p v-for="(paragraph, index) in paragraphs(copy['intro.body'])" :key="index">{{ paragraph }}</p>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-5xl px-4 py-14 sm:px-6 lg:px-8">
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="(stack, index) in stacks"
                    :key="index"
                    class="animate-fade-up rounded-[var(--radius-card)] border p-5"
                    :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                >
                    <component :is="stack.icon" class="h-5 w-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <h2 class="mt-4 text-base font-semibold">{{ stack.title }}</h2>
                    <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">{{ stack.body }}</p>

                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <UiBadge v-for="item in stack.items" :key="item" size="sm">{{ item }}</UiBadge>
                    </div>
                </div>
            </div>
        </section>

        <section class="py-14" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    :eyebrow="copy['plainly.eyebrow']"
                    :title="copy['plainly.title']"
                    :body="copy['plainly.body']"
                />
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
