<script setup>
/**
 * A legal document: the privacy policy, the terms, the refund policy.
 *
 * Written and published in the admin panel (/admin/legal). Each published
 * version keeps its own date and stays readable, so this page says which
 * version it is, since when it applies, what changed, and links the earlier
 * ones. The date is the day the version was published, never today's date.
 *
 * This is not legal advice. Have a lawyer review before the site goes live.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { History } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';

const props = defineProps({
    document: { type: Object, required: true },
    version: { type: Object, required: true },
    // current: the version in force. archived: an earlier one. preview: staff
    // looking at any version, draft or not, from the admin panel.
    mode: { type: String, default: 'current' },
    history: { type: Array, default: () => [] },
    seo: { type: Object, required: true },
});

const heading = computed(() => (props.mode === 'archived'
    ? `${props.document.title}, version ${props.version.number}`
    : props.document.title));

const status = computed(() => {
    if (!props.version.publishedAt) return `Version ${props.version.number}, a draft that has not been published`;
    if (props.mode === 'archived') {
        return `Version ${props.version.number}, in force from ${props.version.publishedAt}`
            + (props.version.supersededAt ? ` until ${props.version.supersededAt}` : '');
    }

    return `Version ${props.version.number}, in force since ${props.version.publishedAt}`;
});
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <div
            v-if="mode !== 'current'"
            class="border-b px-4 py-2 text-center text-sm"
            style="background: color-mix(in oklab, var(--color-warn-500) 14%, var(--surface)); border-color: var(--border-subtle)"
            role="status"
        >
            <template v-if="mode === 'preview'">
                Preview of version {{ version.number }}.
                {{ version.publishedAt ? 'This version has been published.' : 'Visitors cannot see it until it is published.' }}
                Search engines are told not to index this copy.
            </template>
            <template v-else>
                This is an earlier version, kept so you can read what applied at the time.
                <Link :href="document.path" class="font-semibold underline">Read the version in force</Link>.
            </template>
        </div>

        <section class="border-b" style="border-color: var(--border-subtle)">
            <div class="mx-auto max-w-3xl px-4 py-14 sm:px-6 lg:px-8">
                <nav class="mb-4 flex items-center gap-1.5 text-xs" style="color: var(--text-muted)" aria-label="Breadcrumb">
                    <Link href="/" class="transition hover:text-[var(--text-strong)]">Home</Link>
                    <span>/</span>
                    <template v-if="mode === 'archived'">
                        <Link :href="document.path" class="transition hover:text-[var(--text-strong)]">{{ document.title }}</Link>
                        <span>/</span>
                        <span>Version {{ version.number }}</span>
                    </template>
                    <span v-else>{{ document.title }}</span>
                </nav>

                <h1 class="text-3xl font-semibold leading-tight">{{ heading }}</h1>
                <p v-if="version.intro" class="mt-4 text-base leading-relaxed" style="color: var(--text-muted)">{{ version.intro }}</p>
                <p class="mt-3 text-xs" style="color: var(--text-muted)">{{ status }}</p>
                <p v-if="version.changeNote" class="mt-1 text-xs" style="color: var(--text-muted)">
                    What changed in this version: {{ version.changeNote }}
                </p>
            </div>
        </section>

        <section class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
            <MarkdownHtml :html="version.html" class="text-base" />

            <section v-if="history.length > 1" class="mt-12" aria-labelledby="legal-versions">
                <h2 id="legal-versions" class="flex items-center gap-2 text-sm font-semibold" style="color: var(--text-strong)">
                    <History class="h-4 w-4" style="color: var(--text-muted)" />
                    Every version of this document
                </h2>
                <ol class="mt-3 divide-y rounded-xl border text-sm" style="border-color: var(--border-subtle)">
                    <li
                        v-for="entry in history"
                        :key="entry.number"
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-3"
                        style="border-color: var(--border-subtle)"
                    >
                        <span v-if="entry.shown" class="font-medium" style="color: var(--text-strong)" aria-current="page">Version {{ entry.number }}</span>
                        <Link v-else :href="entry.href" class="font-medium text-brand-600 hover:underline dark:text-brand-400">Version {{ entry.number }}</Link>
                        <span style="color: var(--text-muted)">{{ entry.publishedAt }}</span>
                        <span v-if="entry.current" class="text-xs font-medium" style="color: var(--text-muted)">· in force</span>
                        <span v-if="entry.changeNote" class="w-full" style="color: var(--text-muted)">{{ entry.changeNote }}</span>
                    </li>
                </ol>
            </section>

            <div
                class="mt-10 rounded-xl border p-5"
                style="border-color: var(--border-subtle); background: var(--surface-sunken)"
            >
                <p class="text-sm" style="color: var(--text-base)">
                    Questions about this page? Write to us through the
                    <Link href="/contact" class="font-semibold text-brand-600 hover:underline dark:text-brand-400">
                        contact form
                    </Link>
                    and we will answer.
                </p>
            </div>
        </section>
    </PublicLayout>
</template>
