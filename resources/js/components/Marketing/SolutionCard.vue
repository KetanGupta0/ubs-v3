<script setup>
/**
 * A catalogue card.
 *
 * The whole card is the link, and the hover state lifts it and reveals the
 * illustrative screen behind the summary. The animation is doing a job: it
 * shows the shape of the product without needing a second click.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight, Layers, Clock, IndianRupee } from 'lucide-vue-next';

import { accentFor } from '@/support/accents';
import MockScreen from './MockScreen.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

const props = defineProps({
    solution: { type: Object, required: true },
    /** Delay in the staggered entrance, in milliseconds. */
    delay: { type: Number, default: 0 },
});

const tone = computed(() => accentFor(props.solution.accent));
</script>

<template>
    <Link
        :href="`/solutions/${solution.slug}`"
        class="group animate-fade-up relative flex flex-col overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)] shadow-[var(--shadow-card)] transition-[transform,box-shadow,border-color] duration-[var(--duration-base)] ease-[var(--ease-out-expo)] hover:-translate-y-1.5 hover:shadow-[var(--shadow-pop)]"
        :style="{ borderColor: 'var(--border-subtle)', animationDelay: `${delay}ms` }"
        @mouseenter="$el.style.borderColor = tone.border"
        @mouseleave="$el.style.borderColor = 'var(--border-subtle)'"
    >
        <!-- Accent wash that warms on hover. -->
        <span
            class="pointer-events-none absolute inset-x-0 top-0 h-32 opacity-0 transition-opacity duration-[var(--duration-slow)] group-hover:opacity-100"
            :style="{ background: `linear-gradient(to bottom, ${tone.soft}, transparent)` }"
            aria-hidden="true"
        />

        <div class="relative p-5 sm:p-6">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-xs font-medium uppercase tracking-wide" :style="{ color: tone.solid }">
                            {{ solution.category }}
                        </span>
                        <span
                            v-if="solution.featured"
                            class="rounded-full px-2 py-0.5 text-[0.6rem] font-semibold uppercase tracking-wide text-white"
                            :style="{ background: tone.gradient }"
                        >
                            Featured
                        </span>
                    </p>
                    <h3 class="mt-1.5 text-lg font-semibold leading-snug">{{ solution.title }}</h3>
                </div>

                <span
                    class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg transition-transform duration-[var(--duration-base)] group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                    :style="{ background: tone.soft, color: tone.solid }"
                >
                    <ArrowUpRight class="h-4 w-4" aria-hidden="true" />
                </span>
            </div>

            <p class="mt-2 text-sm font-medium" style="color: var(--text-base)">{{ solution.tagline }}</p>
            <p class="mt-2 text-sm leading-relaxed" style="color: var(--text-muted)">{{ solution.summary }}</p>

            <!-- Facts a buyer wants before clicking. -->
            <dl class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs" style="color: var(--text-muted)">
                <div v-if="solution.moduleCount" class="flex items-center gap-1.5">
                    <Layers class="h-3.5 w-3.5" aria-hidden="true" />
                    <dt class="sr-only">Modules</dt>
                    <dd>{{ solution.moduleCount }} modules</dd>
                </div>
                <div v-if="solution.timeline" class="flex items-center gap-1.5">
                    <Clock class="h-3.5 w-3.5" aria-hidden="true" />
                    <dt class="sr-only">Typical timeline</dt>
                    <dd>{{ solution.timeline }}</dd>
                </div>
                <div v-if="solution.priceBand" class="flex items-center gap-1.5">
                    <IndianRupee class="h-3.5 w-3.5" aria-hidden="true" />
                    <dt class="sr-only">Indicative budget</dt>
                    <dd class="tnum">{{ solution.priceBand }}</dd>
                </div>
            </dl>

            <div v-if="solution.techStack?.length" class="mt-4 flex flex-wrap gap-1.5">
                <UiBadge v-for="tech in solution.techStack" :key="tech" size="sm">{{ tech }}</UiBadge>
            </div>
        </div>

        <!-- The illustrative screen, peeking and rising on hover. -->
        <div class="relative mt-auto h-24 overflow-hidden px-5 sm:px-6">
            <div
                class="translate-y-6 transition-transform duration-[var(--duration-slow)] ease-[var(--ease-out-expo)] group-hover:translate-y-0"
            >
                <MockScreen
                    :slug="solution.slug"
                    :accent="solution.accent"
                    :modules="solution.techStack"
                    size="compact"
                />
            </div>
        </div>
    </Link>
</template>
