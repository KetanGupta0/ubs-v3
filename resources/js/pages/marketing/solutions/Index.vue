<script setup>
/**
 * The solutions catalogue.
 *
 * Search and four facets, all in the query string so a filtered view survives a
 * refresh and can be sent to someone else. Filtering happens on the server, so
 * the result is the same whether you arrive by link or by clicking.
 */
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Search, X, SlidersHorizontal, Building2, ShoppingBag, HeartPulse, Truck, Landmark } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import SolutionCard from '@/components/Marketing/SolutionCard.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    solutions: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    facets: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    totalCount: { type: Number, default: 0 },
    seo: { type: Object, required: true },
});

const categoryIcons = { Building2, ShoppingBag, HeartPulse, Truck, Landmark };

const search = ref(props.filters.q ?? '');
const filtersOpen = ref(false);
let debounce = null;

/** Rebuild the query string, dropping anything empty so URLs stay readable. */
function apply(overrides = {}) {
    const query = {
        q: search.value || undefined,
        category: props.filters.category || undefined,
        industry: props.filters.industry || undefined,
        platform: props.filters.platform || undefined,
        tech: props.filters.tech || undefined,
        ...overrides,
    };

    Object.keys(query).forEach((key) => {
        if (!query[key]) delete query[key];
    });

    router.get('/solutions', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['solutions', 'filters'],
    });
}

watch(search, () => {
    clearTimeout(debounce);
    debounce = setTimeout(() => apply({ q: search.value || undefined }), 300);
});

/** Clicking an active facet clears it, which is what people expect. */
function toggle(key, value) {
    apply({ [key]: props.filters[key] === value ? undefined : value });
}

function clearAll() {
    search.value = '';
    router.get('/solutions', {}, { preserveScroll: true, replace: true });
}

const activeFilters = computed(() =>
    ['category', 'industry', 'platform', 'tech']
        .filter((key) => props.filters[key])
        .map((key) => ({ key, value: props.filters[key] })),
);

const hasFilters = computed(() => activeFilters.value.length > 0 || Boolean(props.filters.q));

/** How many options to show before the "show all" toggle. */
const FACET_LIMIT = 10;

const expanded = ref(new Set());

function toggleExpanded(key) {
    const next = new Set(expanded.value);
    next.has(key) ? next.delete(key) : next.add(key);
    expanded.value = next;
}

const facetGroups = computed(() =>
    [
        { key: 'industry', label: 'Industry', all: props.facets.industries ?? [] },
        { key: 'platform', label: 'Platform', all: props.facets.platforms ?? [] },
        { key: 'tech', label: 'Technology', all: props.facets.tech ?? [] },
    ].map((group) => {
        const isOpen = expanded.value.has(group.key);
        // An active value must stay visible even if it sits in the long tail.
        const active = props.filters[group.key];
        const visible = isOpen ? group.all : group.all.slice(0, FACET_LIMIT);

        return {
            ...group,
            options: active && !visible.includes(active) ? [active, ...visible] : visible,
            hiddenCount: group.all.length - Math.min(group.all.length, FACET_LIMIT),
            expanded: isOpen,
        };
    }),
);
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <!-- ---------------------------------------------------------- header -->
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>Solutions</span>
                </nav>

                <h1 class="max-w-3xl text-3xl font-semibold leading-tight sm:text-4xl">
                    Software we build, and what is actually inside each one
                </h1>

                <p class="mt-4 max-w-2xl text-base leading-relaxed" style="color: var(--text-muted)">
                    {{ totalCount }} products across {{ categories.length }} categories. Every one
                    lists its modules, the technology it runs on, an indicative budget band and an
                    indicative timeline, so you can tell whether we are in the right range before
                    you spend time on a call.
                </p>

                <!-- Search sits in the header, because searching is the main verb here. -->
                <div class="mt-7 flex flex-wrap gap-2.5">
                    <div class="min-w-0 flex-1 sm:max-w-md">
                        <UiInput
                            v-model="search"
                            type="search"
                            size="lg"
                            placeholder="Search by product, industry or technology…"
                            aria-label="Search the solutions catalogue"
                        >
                            <template #leading><Search class="h-4 w-4" /></template>
                        </UiInput>
                    </div>

                    <UiButton variant="secondary" size="lg" class="lg:hidden" @click="filtersOpen = true">
                        <template #leading><SlidersHorizontal class="h-4 w-4" /></template>
                        Filters
                        <UiBadge v-if="activeFilters.length" tone="brand" size="sm">{{ activeFilters.length }}</UiBadge>
                    </UiButton>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
            <div class="lg:grid lg:grid-cols-12 lg:gap-10">
                <!-- ------------------------------------------- desktop filters -->
                <aside class="hidden lg:col-span-3 lg:block">
                    <div class="sticky top-24 space-y-7">
                        <div>
                            <h2 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                Category
                            </h2>
                            <ul class="mt-3 space-y-0.5">
                                <li v-for="category in categories" :key="category.slug">
                                    <button
                                        type="button"
                                        :class="[
                                            'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition',
                                            filters.category === category.slug
                                                ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                                                : 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)]',
                                        ]"
                                        @click="toggle('category', category.slug)"
                                    >
                                        <component
                                            :is="categoryIcons[category.icon] ?? Building2"
                                            class="h-4 w-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1 truncate">{{ category.name }}</span>
                                        <span class="text-xs tnum" style="color: var(--text-muted)">{{ category.count }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div v-for="group in facetGroups" :key="group.key">
                            <h2 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                                {{ group.label }}
                            </h2>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <button
                                    v-for="option in group.options"
                                    :key="option"
                                    type="button"
                                    :class="[
                                        'rounded-full border px-2.5 py-1 text-xs transition',
                                        filters[group.key] === option
                                            ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                                            : 'text-[var(--text-base)] hover:border-[var(--border-strong)]',
                                    ]"
                                    :style="filters[group.key] === option ? {} : { borderColor: 'var(--border-subtle)' }"
                                    @click="toggle(group.key, option)"
                                >
                                    {{ option }}
                                </button>
                            </div>

                            <button
                                v-if="group.hiddenCount > 0"
                                type="button"
                                class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                                @click="toggleExpanded(group.key)"
                            >
                                {{ group.expanded ? 'Show fewer' : `Show all ${group.all.length}` }}
                            </button>
                        </div>

                        <UiButton v-if="hasFilters" variant="ghost" size="sm" block @click="clearAll">
                            <template #leading><X class="h-3.5 w-3.5" /></template>
                            Clear all filters
                        </UiButton>
                    </div>
                </aside>

                <!-- ---------------------------------------------------- results -->
                <main class="lg:col-span-9">
                    <div class="mb-5 flex flex-wrap items-center gap-2">
                        <p class="text-sm" style="color: var(--text-muted)">
                            <span class="font-semibold" style="color: var(--text-strong)">{{ solutions.length }}</span>
                            {{ solutions.length === 1 ? 'product' : 'products' }}
                            <template v-if="hasFilters">matching</template>
                        </p>

                        <button
                            v-for="active in activeFilters"
                            :key="`${active.key}-${active.value}`"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full bg-[var(--surface-sunken)] px-2.5 py-1 text-xs font-medium transition hover:text-[var(--text-strong)]"
                            style="color: var(--text-muted)"
                            @click="toggle(active.key, active.value)"
                        >
                            {{ active.value }}
                            <X class="h-3 w-3" />
                        </button>

                        <button
                            v-if="hasFilters"
                            type="button"
                            class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                            @click="clearAll"
                        >
                            Clear
                        </button>
                    </div>

                    <div v-if="solutions.length" class="grid gap-5 sm:grid-cols-2">
                        <SolutionCard
                            v-for="(solution, index) in solutions"
                            :key="solution.slug"
                            :solution="solution"
                            :delay="Math.min(index * 60, 400)"
                        />
                    </div>

                    <div
                        v-else
                        class="rounded-[var(--radius-card)] border"
                        style="border-color: var(--border-subtle)"
                    >
                        <UiEmptyState
                            :icon="Search"
                            title="Nothing matches that combination"
                            description="Try removing a filter, or tell us what you need and we will say whether we can build it."
                        >
                            <template #action>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <UiButton variant="secondary" @click="clearAll">Clear filters</UiButton>
                                    <UiButton href="/contact">Describe what you need</UiButton>
                                </div>
                            </template>
                        </UiEmptyState>
                    </div>
                </main>
            </div>
        </div>

        <CtaSection
            title="Not seeing what you need?"
            body="The catalogue covers what we build most often, not the limit of what we can build. Describe the problem and we will tell you honestly whether it is a fit."
            primary-label="Describe your project"
        />

        <!-- ---------------------------------------------------- phone filters -->
        <UiDrawer :open="filtersOpen" title="Filter solutions" @close="filtersOpen = false">
            <div class="space-y-6">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">Category</h3>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <button
                            v-for="category in categories"
                            :key="category.slug"
                            type="button"
                            :class="[
                                'rounded-full border px-3 py-1.5 text-sm transition',
                                filters.category === category.slug
                                    ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                                    : 'text-[var(--text-base)]',
                            ]"
                            :style="filters.category === category.slug ? {} : { borderColor: 'var(--border-subtle)' }"
                            @click="toggle('category', category.slug)"
                        >
                            {{ category.name }}
                        </button>
                    </div>
                </div>

                <div v-for="group in facetGroups" :key="group.key">
                    <h3 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        {{ group.label }}
                    </h3>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <button
                            v-for="option in group.options"
                            :key="option"
                            type="button"
                            :class="[
                                'rounded-full border px-3 py-1.5 text-sm transition',
                                filters[group.key] === option
                                    ? 'border-brand-500 bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300'
                                    : 'text-[var(--text-base)]',
                            ]"
                            :style="filters[group.key] === option ? {} : { borderColor: 'var(--border-subtle)' }"
                            @click="toggle(group.key, option)"
                        >
                            {{ option }}
                        </button>
                    </div>

                    <button
                        v-if="group.hiddenCount > 0"
                        type="button"
                        class="mt-2 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400"
                        @click="toggleExpanded(group.key)"
                    >
                        {{ group.expanded ? 'Show fewer' : `Show all ${group.all.length}` }}
                    </button>
                </div>
            </div>

            <template #footer>
                <div class="flex gap-2">
                    <UiButton variant="secondary" block @click="clearAll(); filtersOpen = false">Clear</UiButton>
                    <UiButton block @click="filtersOpen = false">
                        Show {{ solutions.length }} {{ solutions.length === 1 ? 'result' : 'results' }}
                    </UiButton>
                </div>
            </template>
        </UiDrawer>
    </PublicLayout>
</template>
