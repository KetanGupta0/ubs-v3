<script setup>
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, Download } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    document: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Versions of ${document.name}`" />

    <AppLayout
        :title="document.name"
        :breadcrumbs="[
            { label: 'Overview', href: '/client' },
            { label: 'Documents', href: '/client/documents' },
            { label: 'Versions' },
        ]"
    >
        <div class="mx-auto max-w-2xl space-y-5">
            <PageHeader
                :title="document.name"
                description="Every version is kept. An earlier one is still downloadable."
            >
                <template #actions>
                    <UiButton href="/client/documents" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Back
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="version in versions"
                        :key="version.id"
                        class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2 text-sm font-medium">
                                Version {{ version.version }}
                                <UiBadge v-if="version.current" tone="success" size="sm">current</UiBadge>
                            </span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ version.size }} · {{ version.at }}
                            </span>
                        </span>

                        <UiButton
                            :href="`/client/documents/${version.id}/download`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                        >
                            <template #leading><Download class="h-3.5 w-3.5" /></template>
                            Download
                        </UiButton>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
