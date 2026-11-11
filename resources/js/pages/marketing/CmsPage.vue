<script setup>
/** A page written and published from the admin panel. */
import { Link } from '@inertiajs/vue3';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import PageBlocks from '@/components/Marketing/PageBlocks.vue';

defineProps({
    page: { type: Object, required: true },
    preview: { type: Boolean, default: false },
    seo: { type: Object, required: true },
});
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <div
            v-if="preview"
            class="border-b px-4 py-2 text-center text-sm"
            style="background: color-mix(in oklab, var(--color-warn-500) 14%, var(--surface)); border-color: var(--border-subtle)"
            role="status"
        >
            Preview. Visitors see this page only once it is published, and search engines are told not to index this copy.
        </div>

        <section class="border-b" style="border-color: var(--border-subtle)">
            <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <span>{{ page.title }}</span>
                </nav>
                <h1 class="text-3xl font-semibold leading-tight sm:text-4xl">{{ page.title }}</h1>
                <p v-if="page.summary" class="mt-4 text-lg" style="color: var(--text-muted)">{{ page.summary }}</p>
            </div>
        </section>

        <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <PageBlocks :blocks="page.blocks" />
            <p v-if="page.updatedAt" class="mt-12 text-xs" style="color: var(--text-muted)">Last updated {{ page.updatedAt }}</p>
        </section>
    </PublicLayout>
</template>
