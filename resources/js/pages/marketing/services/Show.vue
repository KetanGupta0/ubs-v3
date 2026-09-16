<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Check, ChevronDown, Code2, ArrowUpCircle, LifeBuoy } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SectionHeading from '@/components/Marketing/SectionHeading.vue';
import EnquiryForm from '@/components/Marketing/EnquiryForm.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    service: { type: Object, required: true },
    otherServices: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const icons = { Code2, ArrowUpCircle, LifeBuoy };
const openFaq = ref(0);

const paragraphs = (props.service.description ?? '').split('\n\n').filter(Boolean);
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div
                class="pointer-events-none absolute -right-32 -top-40 h-[30rem] w-[30rem] rounded-full opacity-20 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-4xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-5 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <Link href="/services" class="transition hover:text-[var(--text-strong)]">Services</Link>
                </nav>

                <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                    <component :is="icons[service.icon] ?? Code2" class="h-5 w-5" aria-hidden="true" />
                </span>

                <h1 class="mt-5 text-3xl font-semibold leading-tight sm:text-4xl">{{ service.title }}</h1>
                <p class="mt-3 text-lg" style="color: var(--text-base)">{{ service.tagline }}</p>
                <p class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">{{ service.summary }}</p>

                <div class="mt-7 flex flex-col gap-3 sm:flex-row">
                    <UiButton size="lg" href="#enquire" :inertia="false">Talk to us about this</UiButton>
                    <UiButton size="lg" variant="secondary" href="/services">
                        <template #leading><ArrowLeft class="h-4 w-4" /></template>
                        All services
                    </UiButton>
                </div>
            </div>
        </section>

        <section v-if="paragraphs.length" class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <div class="space-y-4">
                <p
                    v-for="(paragraph, index) in paragraphs"
                    :key="index"
                    class="text-base leading-relaxed"
                    style="color: var(--text-muted)"
                >
                    {{ paragraph }}
                </p>
            </div>
        </section>

        <section class="py-12" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading eyebrow="Deliverables" title="What you actually get" />

                <ul class="mt-7 grid gap-2.5 sm:grid-cols-2">
                    <li
                        v-for="(item, index) in service.deliverables"
                        :key="item"
                        class="animate-fade-up flex items-start gap-2.5 rounded-xl border bg-[var(--surface)] p-4"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 50}ms` }"
                    >
                        <Check class="mt-0.5 h-4 w-4 shrink-0 text-signal-500" aria-hidden="true" />
                        <span class="text-sm" style="color: var(--text-base)">{{ item }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <section v-if="service.process?.length" class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <SectionHeading eyebrow="How it runs" title="Step by step" />

            <ol class="mt-8 space-y-0">
                <li
                    v-for="(step, index) in service.process"
                    :key="step.step"
                    class="animate-fade-up relative flex gap-5 pb-8 last:pb-0"
                    :style="{ animationDelay: `${index * 80}ms` }"
                >
                    <!-- Connector between the numbered markers. -->
                    <span
                        v-if="index < service.process.length - 1"
                        class="absolute left-[1.125rem] top-10 bottom-0 w-px"
                        style="background: var(--border-subtle)"
                        aria-hidden="true"
                    />

                    <span class="relative z-10 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white tnum">
                        {{ index + 1 }}
                    </span>

                    <div class="min-w-0 pt-1">
                        <h3 class="text-base font-semibold">{{ step.step }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed" style="color: var(--text-muted)">{{ step.body }}</p>
                    </div>
                </li>
            </ol>
        </section>

        <section v-if="service.engagementModels?.length" class="py-12" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading
                    eyebrow="Engagement"
                    title="How we charge"
                    body="Pick the shape that matches how settled the requirement is. We will tell you if you have picked the wrong one."
                />

                <div class="mt-7 grid gap-4 sm:grid-cols-3">
                    <div
                        v-for="(model, index) in service.engagementModels"
                        :key="model.name"
                        class="animate-fade-up flex flex-col rounded-[var(--radius-card)] border bg-[var(--surface)] p-5"
                        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${index * 70}ms` }"
                    >
                        <h3 class="text-base font-semibold">{{ model.name }}</h3>
                        <p class="mt-2 flex-1 text-sm leading-relaxed" style="color: var(--text-muted)">{{ model.body }}</p>
                        <p class="mt-4 border-t pt-3 text-xs" style="border-color: var(--border-subtle); color: var(--text-muted)">
                            Suits: <span class="font-medium" style="color: var(--text-base)">{{ model.suits }}</span>
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="service.faqs?.length" class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <SectionHeading eyebrow="Before you ask" title="Questions people usually have" />

            <ul class="mt-7 space-y-2">
                <li
                    v-for="(faq, index) in service.faqs"
                    :key="faq.q"
                    class="overflow-hidden rounded-xl border"
                    style="border-color: var(--border-subtle)"
                >
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-4 p-4 text-left"
                        :aria-expanded="openFaq === index"
                        @click="openFaq = openFaq === index ? -1 : index"
                    >
                        <span class="text-sm font-semibold">{{ faq.q }}</span>
                        <ChevronDown
                            class="h-4 w-4 shrink-0 transition-transform"
                            :class="openFaq === index && 'rotate-180'"
                            style="color: var(--text-muted)"
                        />
                    </button>

                    <p v-if="openFaq === index" class="px-4 pb-4 text-sm leading-relaxed" style="color: var(--text-muted)">
                        {{ faq.a }}
                    </p>
                </li>
            </ul>
        </section>

        <section id="enquire" class="py-12" style="background: var(--surface-sunken)">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <SectionHeading align="center" eyebrow="Get in touch" :title="`Talk to us about ${service.title.toLowerCase()}`" />

                <div class="mt-8">
                    <EnquiryForm
                        interest="service"
                        :service-slug="service.slug"
                        :context-label="service.title"
                        :show-project-fields="service.type !== 'maintenance'"
                    />
                </div>
            </div>
        </section>

        <section v-if="otherServices.length" class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Other services</h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <Link
                    v-for="other in otherServices"
                    :key="other.slug"
                    :href="`/services/${other.slug}`"
                    class="group flex items-center gap-3 rounded-xl border p-4 transition hover:border-brand-300 dark:hover:border-brand-700"
                    style="border-color: var(--border-subtle)"
                >
                    <component :is="icons[other.icon] ?? Code2" class="h-4.5 w-4.5 shrink-0 text-brand-500" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{ other.title }}</span>
                        <span class="block truncate text-xs" style="color: var(--text-muted)">{{ other.tagline }}</span>
                    </span>
                    <ArrowRight class="h-4 w-4 shrink-0 transition-transform group-hover:translate-x-0.5" style="color: var(--text-muted)" />
                </Link>
            </div>
        </section>
    </PublicLayout>
</template>
