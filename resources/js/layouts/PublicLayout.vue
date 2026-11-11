<script setup>
/**
 * Shell for the marketing site.
 *
 * The header splits the two business lines rather than blending them, because
 * a visitor arrives wanting either software built or training taken, and the
 * fastest way to lose them is to make them work out which door is theirs.
 */
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, X, ArrowUpRight } from 'lucide-vue-next';

import BrandLogo from '@/components/Brand/BrandLogo.vue';
import ThemeToggle from '@/components/Nav/ThemeToggle.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiToaster from '@/components/UI/UiToaster.vue';

const menuOpen = ref(false);
const scrolled = ref(false);

/*
 * Legal documents come from the server, because which ones exist is decided in
 * the admin panel: a refund policy appears here the day it is published.
 */
const page = usePage();
const legalLinks = computed(() => page.props.legal ?? [
    { title: 'Privacy policy', href: '/legal/privacy' },
    { title: 'Terms of service', href: '/legal/terms' },
]);

/*
 * The header and footer menus are set in the admin panel (/admin/menus). The
 * lists below are only what to show if the server sent none.
 */
const fallbackMenus = {
    header: [
        { label: 'Solutions', href: '/solutions' },
        { label: 'Services', href: '/services' },
        { label: 'Internships', href: '/internships' },
        { label: 'Training', href: '/training' },
        { label: 'About', href: '/about' },
    ],
    footer: [
        { title: 'Company', links: [{ label: 'Contact', href: '/contact' }] },
        { title: 'Account', links: [{ label: 'Sign in', href: '/login' }] },
    ],
};

const menus = computed(() => page.props.menus ?? fallbackMenus);
const links = computed(() => menus.value.header);
const footerColumns = computed(() => menus.value.footer);

/** The drawer on a phone offers Contact even when the header does not. */
const drawerLinks = computed(() => (links.value.some((link) => link.href === '/contact')
    ? links.value
    : [...links.value, { label: 'Contact', href: '/contact' }]));

const isCurrent = (href) => {
    const url = page.url.split(/[?#]/)[0];
    const path = href.split(/[?#]/)[0];

    return path === '/' ? url === '/' : url === path || url.startsWith(`${path}/`);
};

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
                    <component
                        :is="link.external ? 'a' : Link"
                        v-for="link in links"
                        :key="`${link.label}${link.href}`"
                        :href="link.href"
                        :target="link.external ? '_blank' : undefined"
                        :rel="link.external ? 'noopener' : undefined"
                        :aria-current="isCurrent(link.href) ? 'page' : undefined"
                        :class="[
                            'rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-[var(--surface-sunken)]',
                            isCurrent(link.href) && 'text-brand-600 dark:text-brand-400',
                        ]"
                        :style="isCurrent(link.href) ? {} : { color: 'var(--text-base)' }"
                    >
                        {{ link.label }}
                    </component>
                </nav>

                <div class="ml-auto flex items-center gap-2">
                    <span class="hidden sm:contents">
                        <ThemeToggle />
                        <UiButton variant="ghost" size="sm" href="/login">Sign in</UiButton>
                    </span>

                    <UiButton size="sm" href="/contact">
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

                    <div v-for="(column, index) in footerColumns" :key="index">
                        <h3 class="text-sm font-semibold">{{ column.title }}</h3>
                        <ul class="mt-3 space-y-2 text-sm" style="color: var(--text-muted)">
                            <li v-for="item in column.links" :key="`${item.label}${item.href}`">
                                <component
                                    :is="item.external ? 'a' : Link"
                                    :href="item.href"
                                    :target="item.external ? '_blank' : undefined"
                                    :rel="item.external ? 'noopener' : undefined"
                                    class="transition hover:text-[var(--text-strong)]"
                                >
                                    {{ item.label }}
                                </component>
                            </li>
                            <!-- Published legal documents always close the last column. -->
                            <template v-if="index === footerColumns.length - 1">
                                <li v-for="document in legalLinks" :key="document.href">
                                    <Link :href="document.href" class="transition hover:text-[var(--text-strong)]">{{ document.title }}</Link>
                                </li>
                            </template>
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
                <component
                    :is="link.external ? 'a' : Link"
                    v-for="link in drawerLinks"
                    :key="`${link.label}${link.href}`"
                    :href="link.href"
                    :target="link.external ? '_blank' : undefined"
                    :rel="link.external ? 'noopener' : undefined"
                    class="block rounded-xl px-3 py-3 text-base font-medium transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-strong)"
                    @click="menuOpen = false"
                >
                    {{ link.label }}
                </component>
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
