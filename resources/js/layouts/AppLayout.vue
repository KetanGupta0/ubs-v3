<script setup>
/**
 * The authenticated shell for all three roles.
 *
 * Desktop gets a collapsible sidebar. Phones get a fixed bottom tab bar and a
 * More sheet, which is what a native app does and what a thumb can actually
 * reach. The navigation set is chosen by role, so a client never sees a
 * student's menu even momentarily.
 */
import { ref, computed, onMounted, nextTick } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell, LogOut, Settings, ShieldCheck, PanelLeftClose, PanelLeft,
    MoreHorizontal, ChevronRight, ChevronDown,
} from 'lucide-vue-next';

import BrandLogo from '@/components/Brand/BrandLogo.vue';
import BrandMark from '@/components/Brand/BrandMark.vue';
import NavItem from '@/components/Nav/NavItem.vue';
import SearchPalette from '@/components/Search/SearchPalette.vue';
import ThemeToggle from '@/components/Nav/ThemeToggle.vue';
import UiAvatar from '@/components/UI/UiAvatar.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiDropdownItem from '@/components/UI/UiDropdownItem.vue';
import UiToaster from '@/components/UI/UiToaster.vue';
import UiButton from '@/components/UI/UiButton.vue';
import { navigationFor, primaryNavFor, secondaryNavFor, groupNav, roleLabels } from '@/support/navigation';

const props = defineProps({
    title: { type: String, default: null },
    /** Array of { label, href? } rendered above the title. */
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();

const user = computed(() => page.props.auth?.user ?? null);
const role = computed(() => user.value?.role ?? 'student');

/*
 * Only admin navigation is filtered. Clients and students carry no permissions
 * at all, so null keeps their navigation whole, while a staff account with
 * nothing granted correctly sees only what everybody gets.
 */
const permissions = computed(() =>
    role.value === 'admin' ? (page.props.auth?.permissions ?? []) : null,
);

const items = computed(() => navigationFor(role.value, permissions.value));
const primary = computed(() => primaryNavFor(role.value, permissions.value));
const secondary = computed(() => secondaryNavFor(role.value, permissions.value));

const groups = computed(() => groupNav(items.value));
const secondaryGroups = computed(() => groupNav(secondary.value));

/*
 * The sidebar's width and which groups are closed, remembered in this
 * browser. Every page mounts its own copy of this layout, so without this the
 * sidebar sprang open again on each click. Read before the first render so it
 * does not flash open and then shut.
 */
function remembered(key, fallback) {
    try {
        const value = window.localStorage.getItem(key);
        return value === null ? fallback : JSON.parse(value);
    } catch {
        return fallback;
    }
}

function remember(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Private browsing or storage full: it simply is not remembered.
    }
}

const collapsed = ref(remembered('nav.sidebar-collapsed', false) === true);
const closedGroups = ref(remembered('nav.closed-groups', []));
const moreOpen = ref(false);

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    remember('nav.sidebar-collapsed', collapsed.value);
}

function toggleGroup(label) {
    closedGroups.value = closedGroups.value.includes(label)
        ? closedGroups.value.filter((entry) => entry !== label)
        : [...closedGroups.value, label];
    remember('nav.closed-groups', closedGroups.value);
}

// Icons alone carry no headings, so a narrow sidebar shows every item.
const isOpen = (group) => collapsed.value || !group.label || !closedGroups.value.includes(group.label);
const groupId = (label) => `nav-group-${String(label).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;

/*
 * The most specific match wins, and only that one.
 *
 * A plain prefix test lit up Dashboard on every single admin page, because
 * every admin path begins with /admin. Two highlighted items tell you nothing
 * about where you are.
 */
/*
 * Compared on the path alone. A list keeps its search and filters in the query
 * string, so a filtered list (or a link that opens one pre-filtered) would
 * otherwise light up nothing at all.
 */
const pathOf = (href) => href.split(/[?#]/)[0].replace(/\/$/, '');

const activeHref = computed(() => {
    const url = pathOf(page.url);

    return items.value
        .map((item) => item.href)
        .filter(Boolean)
        .filter((href) => url === pathOf(href) || url.startsWith(`${pathOf(href)}/`))
        .sort((a, b) => pathOf(b).length - pathOf(a).length)[0] ?? null;
});

function isActive(item) {
    return Boolean(item.href) && item.href === activeHref.value;
}

/*
 * The page you are on is never hidden: its group opens, and the sidebar
 * scrolls to it, because a highlight below the fold tells you nothing.
 */
const sidebarNav = ref(null);

onMounted(() => {
    const activeGroup = items.value.find((item) => isActive(item))?.group;

    if (activeGroup && closedGroups.value.includes(activeGroup)) {
        toggleGroup(activeGroup);
    }

    nextTick(() => sidebarNav.value?.querySelector('[aria-current="page"]')?.scrollIntoView({ block: 'nearest' }));
});
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

            <nav ref="sidebarNav" class="flex-1 overflow-y-auto scrollbar-thin p-3" aria-label="Main">
                <div v-for="(group, index) in groups" :key="group.label ?? 'all'" :class="index > 0 && 'mt-3'">
                    <button
                        v-if="group.label && !collapsed"
                        type="button"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-[0.68rem] font-semibold uppercase tracking-wider transition hover:bg-[var(--surface-sunken)]"
                        style="color: var(--text-muted)"
                        :aria-expanded="isOpen(group)"
                        :aria-controls="groupId(group.label)"
                        @click="toggleGroup(group.label)"
                    >
                        <span>{{ group.label }}</span>
                        <ChevronDown :class="['h-3.5 w-3.5 transition-transform', !isOpen(group) && '-rotate-90']" aria-hidden="true" />
                    </button>
                    <div v-else-if="group.label && index > 0" class="mx-2 mb-3 border-t" style="border-color: var(--border-subtle)" />

                    <div v-show="isOpen(group)" :id="group.label ? groupId(group.label) : undefined" class="space-y-0.5">
                        <NavItem
                            v-for="item in group.items"
                            :key="item.label"
                            :item="item"
                            :active="isActive(item)"
                            :collapsed="collapsed"
                            :dense="Boolean(group.label)"
                        />
                    </div>
                </div>
            </nav>

            <div class="border-t p-3" style="border-color: var(--border-subtle)">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm transition hover:bg-[var(--surface-sunken)]"
                    style="color: var(--text-muted)"
                    :aria-label="collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="toggleSidebar"
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
                        <span class="hidden sm:inline-flex"><SearchPalette /></span>

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
                                <UiDropdownItem href="/settings/security">
                                    <ShieldCheck class="h-4 w-4" /> Security
                                </UiDropdownItem>
                                <UiDropdownItem href="/password/change">
                                    <Settings class="h-4 w-4" /> Change password
                                </UiDropdownItem>
                                <UiDropdownItem href="/logout" method="post" danger>
                                    <LogOut class="h-4 w-4" /> Sign out
                                </UiDropdownItem>
                            </div>
                        </UiDropdown>
                    </div>
                </div>

                <slot name="subheader" />
            </header>

            <!--
                A member of staff seeing the platform as this person. Always
                visible and always offering the way back, so it cannot be
                forgotten about or mistaken for their own account.
            -->
            <div
                v-if="page.props.viewingAs"
                role="status"
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2 text-sm sm:px-6"
                style="background: color-mix(in oklab, var(--color-warn-500) 14%, var(--surface)); border-color: var(--border-subtle); color: var(--text-strong)"
            >
                <span>
                    Viewing as <strong>{{ page.props.viewingAs.name }}</strong>. Read only: nothing you do here changes
                    anything. Ends at {{ page.props.viewingAs.endsAt }}.
                </span>
                <UiButton size="xs" variant="secondary" @click="router.post('/viewing-as/stop')">
                    Back to {{ page.props.viewingAs.staff ?? 'my account' }}
                </UiButton>
            </div>

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
            <div class="space-y-4">
                <section v-for="group in secondaryGroups" :key="group.label ?? 'all'">
                    <h3
                        v-if="group.label"
                        class="mb-1 px-3 text-[0.68rem] font-semibold uppercase tracking-wider"
                        style="color: var(--text-muted)"
                    >
                        {{ group.label }}
                    </h3>
                    <div class="space-y-0.5">
                        <NavItem
                            v-for="item in group.items"
                            :key="item.label"
                            :item="item"
                            :active="isActive(item)"
                            @click="moreOpen = false"
                        />
                    </div>
                </section>
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
