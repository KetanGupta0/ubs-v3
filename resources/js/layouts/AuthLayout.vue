<script setup>
/**
 * Shell for every sign in screen.
 *
 * A split layout on desktop: a brand panel that carries the story, and the form
 * on a clean surface beside it. On phones the panel is dropped entirely rather
 * than stacked, because nobody scrolls past marketing to reach a password
 * field.
 */
import { Link } from '@inertiajs/vue3';
import { ShieldCheck, Smartphone, GraduationCap, ArrowLeft } from 'lucide-vue-next';

import BrandLogo from '@/components/Brand/BrandLogo.vue';
import ThemeToggle from '@/components/Nav/ThemeToggle.vue';
import UiToaster from '@/components/UI/UiToaster.vue';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Narrower card for short forms such as the two factor challenge. */
    width: { type: String, default: 'max-w-md' },
});

const points = [
    { icon: ShieldCheck, text: 'One sign in for clients, students and the team' },
    { icon: Smartphone, text: 'Password, a one time code, or Google' },
    { icon: GraduationCap, text: 'Your projects and your courses, in one place' },
];
</script>

<template>
    <div class="flex min-h-screen" style="background: var(--surface)">
        <!-- ------------------------------------------------------ brand panel -->
        <aside
            class="relative hidden w-[46%] shrink-0 overflow-hidden lg:flex lg:flex-col lg:justify-between"
            style="background: var(--color-ink-950)"
        >
            <div
                class="pointer-events-none absolute -left-24 top-1/4 h-[36rem] w-[36rem] rounded-full opacity-35 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-brand-600), transparent)"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -right-20 bottom-0 h-[26rem] w-[26rem] rounded-full opacity-25 blur-3xl"
                style="background: radial-gradient(closest-side, var(--color-accent-500), transparent)"
                aria-hidden="true"
            />

            <div class="relative p-10">
                <Link href="/" class="inline-block text-white">
                    <BrandLogo size="md" variant="current" />
                </Link>
            </div>

            <div class="relative px-10 pb-4">
                <p class="max-w-md font-[family-name:var(--font-display)] text-3xl font-semibold leading-tight text-white">
                    We build the software, and we train the people who run it.
                </p>

                <ul class="mt-8 space-y-3.5">
                    <li v-for="point in points" :key="point.text" class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-white/10">
                            <component :is="point.icon" class="h-3.5 w-3.5 text-accent-300" aria-hidden="true" />
                        </span>
                        <span class="text-sm text-white/70">{{ point.text }}</span>
                    </li>
                </ul>
            </div>

            <div class="relative p-10 text-xs text-white/40">
                &copy; {{ new Date().getFullYear() }} Unboundbyte Solutions Private Limited
            </div>
        </aside>

        <!-- ------------------------------------------------------------- form -->
        <main class="flex flex-1 flex-col">
            <header class="flex items-center justify-between p-5 sm:px-8">
                <Link href="/" class="lg:hidden">
                    <BrandLogo size="sm" :show-tagline="false" />
                </Link>

                <Link
                    href="/"
                    class="hidden items-center gap-1.5 text-sm transition hover:text-[var(--text-strong)] lg:inline-flex"
                    style="color: var(--text-muted)"
                >
                    <ArrowLeft class="h-3.5 w-3.5" /> Back to the site
                </Link>

                <ThemeToggle />
            </header>

            <div class="flex flex-1 items-center justify-center px-5 pb-12 sm:px-8">
                <div class="w-full" :class="width">
                    <h1 class="text-2xl font-semibold sm:text-3xl">{{ title }}</h1>
                    <p v-if="subtitle" class="mt-2 text-sm" style="color: var(--text-muted)">
                        {{ subtitle }}
                    </p>

                    <div class="mt-7">
                        <slot />
                    </div>

                    <div v-if="$slots.footer" class="mt-7 text-center text-sm" style="color: var(--text-muted)">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </main>

        <UiToaster />
    </div>
</template>
