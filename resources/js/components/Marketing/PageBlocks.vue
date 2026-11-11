<script setup>
/**
 * The blocks of a page written in the admin panel.
 *
 * Text arrives as HTML the server made from Markdown, with anything typed as
 * HTML stripped and unsafe links refused, which is why it can be rendered
 * here. Nothing else on the page is HTML.
 */
import { ArrowRight } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    blocks: { type: Array, default: () => [] },
});

const external = (href) => /^https:\/\//.test(href ?? '');
</script>

<template>
    <div class="space-y-8">
        <template v-for="(block, index) in blocks" :key="index">
            <h2 v-if="block.type === 'heading' && block.level !== 3" class="text-2xl font-semibold" style="color: var(--text-strong)">
                {{ block.text }}
            </h2>
            <h3 v-else-if="block.type === 'heading'" class="text-lg font-semibold" style="color: var(--text-strong)">
                {{ block.text }}
            </h3>

            <!-- eslint-disable-next-line vue/no-v-html -- made safe on the server, see the note above -->
            <div v-else-if="block.type === 'text'" class="cms-text" v-html="block.html" />

            <figure v-else-if="block.type === 'image' && block.url">
                <img :src="block.url" :alt="block.alt" loading="lazy" class="w-full rounded-[var(--radius-card)] border" style="border-color: var(--border-subtle)">
                <figcaption v-if="block.caption" class="mt-2 text-center text-sm" style="color: var(--text-muted)">{{ block.caption }}</figcaption>
            </figure>

            <aside
                v-else-if="block.type === 'callout'"
                class="rounded-[var(--radius-card)] border p-6"
                style="border-color: var(--border-subtle); background: var(--surface-sunken)"
            >
                <p class="text-lg font-semibold" style="color: var(--text-strong)">{{ block.title }}</p>
                <p v-if="block.body" class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">{{ block.body }}</p>
                <UiButton
                    v-if="block.button_href"
                    class="mt-4"
                    :href="block.button_href"
                    :inertia="!external(block.button_href)"
                    :target="external(block.button_href) ? '_blank' : undefined"
                    :rel="external(block.button_href) ? 'noopener' : undefined"
                >
                    {{ block.button_label }}
                    <template #trailing><ArrowRight class="h-4 w-4" /></template>
                </UiButton>
            </aside>

            <div v-else-if="block.type === 'faq'" class="divide-y rounded-[var(--radius-card)] border" style="border-color: var(--border-subtle)">
                <details v-for="(item, i) in block.items" :key="i" class="group px-5 py-4" style="border-color: var(--border-subtle)">
                    <summary class="cursor-pointer list-none font-medium" style="color: var(--text-strong)">{{ item.question }}</summary>
                    <!-- eslint-disable-next-line vue/no-v-html -- made safe on the server -->
                    <div class="cms-text mt-2 text-sm" v-html="item.html" />
                </details>
            </div>
        </template>
    </div>
</template>

<style scoped>
.cms-text :deep(p) { margin: 0 0 0.9em; line-height: 1.7; color: var(--text-base); }
.cms-text :deep(p:last-child) { margin-bottom: 0; }
.cms-text :deep(a) { color: var(--color-brand-600); text-decoration: underline; text-underline-offset: 2px; }
.cms-text :deep(ul) { list-style: disc; padding-left: 1.4em; margin: 0 0 0.9em; }
.cms-text :deep(ol) { list-style: decimal; padding-left: 1.4em; margin: 0 0 0.9em; }
.cms-text :deep(li) { margin: 0.25em 0; line-height: 1.6; }
.cms-text :deep(strong) { color: var(--text-strong); }
.cms-text :deep(h1), .cms-text :deep(h2), .cms-text :deep(h3) { font-weight: 600; color: var(--text-strong); margin: 1.2em 0 0.5em; }
.cms-text :deep(blockquote) { border-left: 3px solid var(--border-strong); padding-left: 1em; color: var(--text-muted); margin: 0 0 0.9em; }
.cms-text :deep(code) { font-family: ui-monospace, monospace; font-size: 0.9em; background: var(--surface-sunken); padding: 0.1em 0.35em; border-radius: 4px; }
</style>
