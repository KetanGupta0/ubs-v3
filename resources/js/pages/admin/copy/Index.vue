<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

defineProps({ pages: { type: Array, required: true } });
</script>

<template>
    <Head title="Page wording" />

    <AppLayout title="Page wording" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Page wording' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader
                title="Page wording"
                description="The words on the site's fixed pages. Changes are saved as a draft, previewed on the real page, and reach visitors when you publish. Only words change; the layout stays as designed."
            />

            <ul class="divide-y overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)]" style="border-color: var(--border-subtle)">
                <li v-for="item in pages" :key="item.key" style="border-color: var(--border-subtle)">
                    <Link :href="`/admin/copy/${item.key}`" class="flex items-center gap-4 px-5 py-4 transition hover:bg-[var(--surface-sunken)]">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium" style="color: var(--text-strong)">{{ item.label }}</span>
                            <span class="block text-sm" style="color: var(--text-muted)">
                                <code class="text-xs">{{ item.path }}</code>
                                · {{ item.fields }} pieces of wording<template v-if="item.edited">, {{ item.edited }} changed from the start</template>
                                <template v-if="item.publishedAt">, last published {{ item.publishedAt }}</template>
                            </span>
                        </span>
                        <UiBadge v-if="item.drafts" tone="warning" size="sm" dot>{{ item.drafts }} unpublished</UiBadge>
                        <ChevronRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
