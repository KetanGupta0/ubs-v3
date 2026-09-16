<script setup>
/**
 * The authenticated shell for all three roles.
 *
 * Desktop gets a collapsible sidebar. Phones get a fixed bottom tab bar and a
 * More sheet, which is what a native app does and what a thumb can actually
 * reach. The navigation set is chosen by role, so a client never sees a
 * student's menu even momentarily.
 */
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Menu, Bell, Search, LogOut, User, Settings, PanelLeftClose, PanelLeft,
    MoreHorizontal, ChevronRight,
} from 'lucide-vue-next';

import BrandLogo from '@/components/Brand/BrandLogo.vue';
import BrandMark from '@/components/Brand/BrandMark.vue';
import NavItem from '@/components/Nav/NavItem.vue';
import ThemeToggle from '@/components/Nav/ThemeToggle.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiDropdownItem from '@/components/UI/UiDropdownItem.vue';
import UiToaster from '@/components/UI/UiToaster.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { navigationFor, primaryNavFor, secondaryNavFor, roleLabels } from '@/support/navigation';

const props = defineProps({
    title: { type: String, default: null },
    /** Array of { label, href? } rendered above the title. */
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const role = computed(() => user.value?.role ?? 'student');

const items = computed(() => navigationFor(role.value));
const primary = computed(() => primaryNavFor(role.value));
const secondary = computed(() => secondaryNavFor(role.value));

const collapsed = ref(false);
const moreOpen = ref(false);

function isActive(item) {
    return Boolean(item.href) && page.url.startsWith(item.href);
}
</script>

<template>
    <div class="min-h-screen" style="background: var(--surface-sunken)">
        <!-- ------------------------------------------------- desktop sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-30 hidden flex-col border-r bg-[var(--surface)] transition-[width] duration-[var(--duration-base)] lg:flex',
                collapsed ? 'w-[4.5rem]' : 'w-64',
            ]"
            style="border-color: var(--border-subtle)"
        >
            <div :class="['flex h-16 items-center border-b px-4', collapsed && 'justify-center px-2']" style="border-color: var(--border-subtle)">
                <Link href="/" class="min-w-0">
                    <BrandLogo v-if="!collapsed" size="sm" :show-tagline="false" />
                    <BrandMark v-else :size="28" />
                </Link>
            </div>

            <nav class="flex-1 space-y-1 overflow-y-auto scrollbar-thin p-3" aria-label="Main">
                <NavItem
                    v-for="item in items"
                    :key="item.label"
                    :item="item"
                    :active="isActive(item)"
                    :collapsed="collapsed"
                />
            </nav>

            <div class="border-t p-3" style="border-color: var(--border-subtle)">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-muted)"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="collapsed = !collapsed"
                >
                    <component :is="collapsed ? PanelLeft : PanelLeftClose" class="h-[18px] w-[18px] shrink-0" />
                    <span v-if="!collapsed">Collapse</span>
                </button>
            </div>
        </aside>

        <!-- ------------------------------------------------------------ main -->
        <div :class="['flex min-h-screen flex-col transition-[padding] duration-[var(--duration-base)]', collapsed ? 'lg:pl-[4.5rem]' : 'lg:pl-64']">
            <header
                class="sticky top-0 z-20 border-b bg-[var(--surface)]/85 backdrop-blur-md"
                style="border-color: var(--border-subtle)"
            >
                <div class="flex h-16 items-center gap-3 px-4 sm:px-6">
                    <Link href="/" class="lg:hidden">
                        <BrandMark :size="28" />
                    </Link>

                    <div class="min-w-0 flex-1">
                        <nav v-if="breadcrumbs.length" class="hidden items-center gap-1.5 text-xs sm:flex" aria-label="Breadcrumb">
                            <template v-for="(crumb, index) in breadcrumbs" :key="crumb.label">
                                <component
                                    :is="crumb.href ? Link : 'span'"
                                    :href="crumb.href || undefined"
                                    :class="crumb.href ? 'hover:text-[var(--text-strong)] transition' : ''"
                                    style="color: var(--text-muted)"
                                >
                                    {{ crumb.label }}
                                </component>
                                <ChevronRight v-if="index < breadcrumbs.length - 1" class="h-3 w-3" style="color: var(--text-muted)" />
                            </template>
                        </nav>

                        <h1 v-if="title" class="truncate text-base font-semibold sm:text-lg">{{ title }}</h1>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <button
                            type="button"
                            class="hidden h-9 items-center gap-2 rounded-full border px-3 text-sm transition hover:bg-[var(--surface-sunken)] sm:inline-flex"
                            style="border-color: var(--border-subtle); color: var(--text-muted)"
                            aria-label="Search"
                        >
                            <Search class="h-4 w-4" />
                            <span class="hidden md:inline">Search</span>
                            <kbd class="hidden rounded px-1.5 py-0.5 font-mono text-[0.65rem] md:inline" style="background: var(--surface-sunken)">/</kbd>
                        </button>

                        <span class="hidden sm:contents"><ThemeToggle /></span>

                        <button
                            type="button"
                            class="relative inline-flex h-9 w-9 items-center justify-center rounded-full transition hover:bg-[var(--surface-sunken)]"
                            style="color: var(--text-muted)"
                            aria-label="Notifications"
                        >
                            <Bell class="h-[18px] w-[18px]" />
                            <span class="absolute right-2 top-2 h-1.5 w-1.5 rounded-full bg-accent-500" />
                        </button>

                        <UiDropdown>
                            <template #trigger>
                                <button type="button" class="flex items-center gap-2 rounded-full p-0.5 transition hover:bg-[var(--surface-sunken)]">
                                    <UiAvatar :name="user?.name || 'Guest'" :src="user?.avatar_url" size="sm" />
                                </button>
                            </template>

                            <div class="border-b px-2.5 pb-2.5 pt-1.5" style="border-color: var(--border-subtle)">
                                <p class="truncate text-sm font-semibold" style="color: var(--text-strong)">
                                    {{ user?.name || 'Not signed in' }}
                                </p>
                                <p class="truncate text-xs" style="color: var(--text-muted)">
                                    {{ user?.email || roleLabels[role] }}
                                </p>
                            </div>

                            <div class="mt-1 space-y-0.5">
                                <UiDropdownItem><User class="h-4 w-4" /> Profile</UiDropdownItem>
                                <UiDropdownItem><Settings class="h-4 w-4" /> Settings</UiDropdownItem>
                                <UiDropdownItem danger><LogOut class="h-4 w-4" /> Sign out</UiDropdownItem>
                            </div>
                        </UiDropdown>
                    </div>
                </div>

                <slot name="subheader" />
            </header>

            <main class="flex-1 px-4 py-5 pb-28 sm:px-6 sm:py-6 lg:pb-8">
                <slot />
            </main>
        </div>

        <!-- --------------------------------------------------- phone tab bar -->
        <nav
            class="fixed inset-x-0 bottom-0 z-30 border-t bg-[var(--surface)]/92 backdrop-blur-lg lg:hidden"
            style="border-color: var(--border-subtle); padding-bottom: env(safe-area-inset-bottom)"
            aria-label="Primary"
        >
            <div class="grid grid-cols-5">
                <component
                    :is="item.href ? Link : 'button'"
                    v-for="item in primary"
                    :key="item.label"
                    :href="item.href || undefined"
                    :type="item.href ? undefined : 'button'"
                    :aria-current="isActive(item) ? 'page' : undefined"
                    :class="[
                        'flex flex-col items-center gap-1 py-2.5 text-[0.65rem] font-medium transition-colors',
                        isActive(item) ? 'text-brand-600 dark:text-brand-400' : 'text-[var(--text-muted)]',
                    ]"
                >
                    <component :is="item.icon" class="h-5 w-5" aria-hidden="true" />
                    <span class="truncate px-0.5">{{ item.label }}</span>
                </component>

                <button
                    type="button"
                    class="flex flex-col items-center gap-1 py-2.5 text-[0.65rem] font-medium transition-colors"
                    style="color: var(--text-muted)"
                    @click="moreOpen = true"
                >
                    <MoreHorizontal class="h-5 w-5" aria-hidden="true" />
                    <span>More</span>
                </button>
            </div>
        </nav>

        <UiDrawer :open="moreOpen" title="Menu" side="right" @close="moreOpen = false">
            <div class="space-y-1">
                <NavItem
                    v-for="item in secondary"
                    :key="item.label"
                    :item="item"
                    :active="isActive(item)"
                    @click="moreOpen = false"
                />
            </div>

            <template #footer>
                <div class="flex items-center justify-between">
                    <span class="text-sm" style="color: var(--text-muted)">Theme</span>
                    <ThemeToggle />
                </div>
            </template>
        </UiDrawer>

        <UiToaster />
    </div>
</template>
