<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import CtaSection from '@/components/Marketing/CtaSection.vue';

defineProps({
    groups: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

/** Keyed by "group-index" so one open answer per group is possible. */
const open = ref(new Set());

function toggle(key) {
    const next = new Set(open.value);
    next.has(key) ? next.delete(key) : next.add(key);
    open.value = next;
}
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="relative overflow-hidden border-b" style="border-color: var(--border-subtle)">
            <div class="bg-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />

            <div class="relative mx-auto max-w-4xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>FAQ</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">Questions people ask</h1>
                <p class="mt-5 text-base leading-relaxed" style="color: var(--text-muted)">
                    Costs, who owns the code, how projects run, how training works, and what a
                    maintenance contract actually covers.
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-4xl px-4 py-12 sm:px-6 lg:px-8">
            <div v-for="group in groups" :key="group.group" class="mb-10 last:mb-0">
                <h2 class="text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    {{ group.label }}
                </h2>

                <ul class="mt-4 space-y-2">
                    <li
                        v-for="(item, index) in group.items"
                        :key="item.question"
                        class="overflow-hidden rounded-xl border"
                        style="border-color: var(--border-subtle)"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 p-4 text-left transition hover:bg-[var(--surface-sunken)]"
                            :aria-expanded="open.has(`${group.group}-${index}`)"
                            @click="toggle(`${group.group}-${index}`)"
                        >
                            <span class="text-sm font-semibold">{{ item.question }}</span>
                            <ChevronDown
                                class="h-4 w-4 shrink-0 transition-transform"
                                :class="open.has(`${group.group}-${index}`) && 'rotate-180'"
                                style="color: var(--text-muted)"
                            />
                        </button>

                        <p
                            v-if="open.has(`${group.group}-${index}`)"
                            class="px-4 pb-4 text-sm leading-relaxed"
                            style="color: var(--text-muted)"
                        >
                            {{ item.answer }}
                        </p>
                    </li>
                </ul>
            </div>
        </section>

        <CtaSection
            title="Still unanswered?"
            body="Ask us directly. A real person reads every enquiry, and we reply within one working day."
            primary-label="Ask a question"
        />
    </PublicLayout>
</template>
