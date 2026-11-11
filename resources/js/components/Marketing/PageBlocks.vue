<script setup>
/**
 * The blocks of a page written in the admin panel.
 *
 * Text arrives as HTML the server made from Markdown and goes through
 * MarkdownHtml. Nothing else on the page is HTML.
 */
import { ArrowRight } from 'lucide-vue-next';

import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
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

            <MarkdownHtml v-else-if="block.type === 'text'" :html="block.html" />

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
                    <MarkdownHtml class="mt-2 text-sm" :html="item.html" />
                </details>
            </div>
        </template>
    </div>
</template>
