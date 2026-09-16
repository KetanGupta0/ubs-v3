<script setup>
/**
 * Shell for the marketing site.
 *
 * The header splits the two business lines rather than blending them, because
 * a visitor arrives wanting either software built or training taken, and the
 * fastest way to lose them is to make them work out which door is theirs.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Menu, X, ArrowUpRight } from 'lucide-vue-next';

import BrandLogo from '@/components/Brand/BrandLogo.vue';
import ThemeToggle from '@/components/Nav/ThemeToggle.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiToaster from '@/components/UI/UiToaster.vue';

const menuOpen = ref(false);
const scrolled = ref(false);

const links = [
    { label: 'Solutions', href: '#solutions' },
    { label: 'Services', href: '#services' },
    { label: 'Training', href: '#training' },
    { label: 'Process', href: '#process' },
    { label: 'Contact', href: '#contact' },
];

function onScroll() {
    scrolled.value = window.scrollY > 8;
}

onMounted(() => {
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
});

onBeforeUnmount(() => window.removeEventListener('scroll', onScroll));
</script>

<template>
    <div class="flex min-h-screen flex-col" style="background: var(--surface)">
        <header
            :class="[
                'sticky top-0 z-40 transition-[background-color,border-color,box-shadow] duration-[var(--duration-base)]',
                scrolled
                    ? 'border-b bg-[var(--surface)]/85 backdrop-blur-xl shadow-[var(--shadow-card)]'
                    : 'border-b border-transparent',
            ]"
            style="border-color: var(--border-subtle)"
        >
            <div class="mx-auto flex h-16 max-w-7xl items-center gap-6 px-4 sm:px-6 lg:px-8">
                <Link href="/" aria-label="Unboundbyte Solutions home">
                    <BrandLogo size="sm" />
                </Link>

                <nav class="hidden flex-1 items-center gap-1 lg:flex" aria-label="Main">
                    <a
                        v-for="link in links"
                        :key="link.label"
                        :href="link.href"
                        class="rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-[var(--surface-sunken)]"
                        style="color: var(--text-base)"
                    >
                        {{ link.label }}
                    </a>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <ThemeToggle class="hidden sm:inline-flex" />

                    <UiButton variant="ghost" size="sm" class="hidden sm:inline-flex" href="/login">
                        Sign in
                    </UiButton>

                    <UiButton size="sm" href="#contact" :inertia="false">
                        Start a project
                        <template #trailing><ArrowUpRight class="h-3.5 w-3.5" /></template>
                    </UiButton>

                    <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-lg transition hover:bg-[var(--surface-sunken)] lg:hidden"
                        style="color: var(--text-base)"
                        aria-label="Open menu"
                        @click="menuOpen = true"
                    >
                        <Menu class="h-5 w-5" />
                    </button>
                </div>
            </div>
        </header>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t" style="border-color: var(--border-subtle); background: var(--surface-sunken)">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                <div class="grid gap-10 md:grid-cols-4">
                    <div class="md:col-span-2">
                        <BrandLogo size="md" />
                        <p class="mt-4 max-w-sm text-sm" style="color: var(--text-muted)">
                            We build and maintain software, and we train the people who will run it.
                            Two halves of the same problem, handled by one team.
                        </p>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold">Company</h3>
                        <ul class="mt-3 space-y-2 text-sm" style="color: var(--text-muted)">
                            <li><a href="#solutions" class="transition hover:text-[var(--text-strong)]">Solutions</a></li>
                            <li><a href="#services" class="transition hover:text-[var(--text-strong)]">Services</a></li>
                            <li><a href="#training" class="transition hover:text-[var(--text-strong)]">Training</a></li>
                            <li><a href="#contact" class="transition hover:text-[var(--text-strong)]">Contact</a></li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold">Account</h3>
                        <ul class="mt-3 space-y-2 text-sm" style="color: var(--text-muted)">
                            <li><Link href="/login" class="transition hover:text-[var(--text-strong)]">Sign in</Link></li>
                            <li><Link href="/design" class="transition hover:text-[var(--text-strong)]">Design system</Link></li>
                        </ul>
                    </div>
                </div>

                <div
                    class="mt-10 flex flex-col gap-3 border-t pt-6 text-xs sm:flex-row sm:items-center sm:justify-between"
                    style="border-color: var(--border-subtle); color: var(--text-muted)"
                >
                    <p>&copy; {{ new Date().getFullYear() }} Unboundbyte Solutions Private Limited. All rights reserved.</p>
                    <ThemeToggle />
                </div>
            </div>
        </footer>

        <UiDrawer :open="menuOpen" title="Menu" @close="menuOpen = false">
            <nav class="space-y-1">
                <a
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="block rounded-xl px-3 py-3 text-base font-medium transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-strong)"
                    @click="menuOpen = false"
                >
                    {{ link.label }}
                </a>
            </nav>

            <template #footer>
                <div class="space-y-3">
                    <UiButton block href="/login">Sign in</UiButton>
                    <div class="flex items-center justify-between">
                        <span class="text-sm" style="color: var(--text-muted)">Theme</span>
                        <ThemeToggle />
                    </div>
                </div>
            </template>
        </UiDrawer>

        <UiToaster />
    </div>
</template>
