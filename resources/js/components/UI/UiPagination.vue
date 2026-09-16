<script setup>
/**
 * Pagination for a Laravel length aware paginator.
 *
 * Renders a compact window of pages on desktop, and prev/next plus a counter
 * on phones where a page number row does not fit.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({
    /** Laravel paginator meta: current_page, last_page, from, to, total. */
    meta: { type: Object, required: true },
    /** Laravel paginator links array. */
    links: { type: Array, default: () => [] },
});

const pages = computed(() =>
    props.links.filter((link) => !Number.isNaN(Number(link.label))),
);

const previous = computed(() => props.links[0]);
const next = computed(() => props.links[props.links.length - 1]);
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex items-center justify-between gap-3 border-t px-1 py-3"
        style="border-color: var(--border-subtle)"
        aria-label="Pagination"
    >
        <p class="text-xs tnum" style="color: var(--text-muted)">
            <span class="hidden sm:inline">Showing </span>{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} of {{ meta.total }}
        </p>

        <div class="flex items-center gap-1">
            <component
                :is="previous?.url ? Link : 'span'"
                :href="previous?.url || undefined"
                preserve-scroll
                preserve-state
                :class="[
                    'inline-flex h-8 items-center gap-1 rounded-lg px-2 text-sm transition',
                    previous?.url
                        ? 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)]'
                        : 'pointer-events-none opacity-40',
                ]"
                aria-label="Previous page"
            >
                <ChevronLeft class="h-4 w-4" />
                <span class="hidden sm:inline">Prev</span>
            </component>

            <div class="hidden items-center gap-1 sm:flex">
                <component
                    :is="page.url ? Link : 'span'"
                    v-for="page in pages"
                    :key="page.label"
                    :href="page.url || undefined"
                    preserve-scroll
                    preserve-state
                    :aria-current="page.active ? 'page' : undefined"
                    :class="[
                        'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm tnum transition',
                        page.active
                            ? 'bg-brand-600 font-semibold text-white'
                            : 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)]',
                    ]"
                >
                    {{ page.label }}
                </component>
            </div>

            <span class="text-sm tnum sm:hidden" style="color: var(--text-muted)">
                {{ meta.current_page }} / {{ meta.last_page }}
            </span>

            <component
                :is="next?.url ? Link : 'span'"
                :href="next?.url || undefined"
                preserve-scroll
                preserve-state
                :class="[
                    'inline-flex h-8 items-center gap-1 rounded-lg px-2 text-sm transition',
                    next?.url
                        ? 'text-[var(--text-base)] hover:bg-[var(--surface-sunken)]'
                        : 'pointer-events-none opacity-40',
                ]"
                aria-label="Next page"
            >
                <span class="hidden sm:inline">Next</span>
                <ChevronRight class="h-4 w-4" />
            </component>
        </div>
    </nav>
</template>
