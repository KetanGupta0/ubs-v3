<script setup>
import { ArrowRight } from 'lucide-vue-next';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    title: { type: String, default: 'Tell us what you are trying to build' },
    body: {
        type: String,
        default: 'A short conversation is usually enough to tell whether we are the right people for it. If we are not, we will say so.',
    },
    primaryLabel: { type: String, default: 'Start a conversation' },
    primaryHref: { type: String, default: '/contact' },
    secondaryLabel: { type: String, default: null },
    secondaryHref: { type: String, default: null },
});
</script>

<template>
    <section class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
        <div
            class="relative overflow-hidden rounded-[var(--radius-card)] px-6 py-14 text-center sm:px-12"
            style="background: var(--color-ink-950)"
        >
            <div
                class="pointer-events-none absolute -left-24 -top-24 h-96 w-96 rounded-full opacity-30 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-600), transparent)"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-32 -right-16 h-96 w-96 rounded-full opacity-25 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-accent-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative mx-auto max-w-2xl">
                <h2 class="font-[family-name:var(--font-display)] text-2xl font-semibold text-white sm:text-4xl">
                    {{ title }}
                </h2>
                <p class="mt-4 text-base text-white/65">{{ body }}</p>

                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <UiButton size="lg" variant="accent" :href="primaryHref">
                        {{ primaryLabel }}
                        <template #trailing><ArrowRight class="h-4 w-4" /></template>
                    </UiButton>

                    <a
                        v-if="secondaryHref"
                        :href="secondaryHref"
                        class="inline-flex h-12 items-center rounded-xl border border-white/15 px-6 text-base font-medium text-white transition hover:bg-white/10"
                    >
                        {{ secondaryLabel }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</template>
