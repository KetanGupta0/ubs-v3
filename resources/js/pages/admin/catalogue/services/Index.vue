<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus, Pencil, ExternalLink } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ services: { type: Array, default: () => [] } });
</script>

<template>
    <Head title="Services" />

    <AppLayout title="Services" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Services' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader title="Services" description="The three service pages on the public site.">
                <template #actions>
                    <UiButton href="/admin/services/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New service
                    </UiButton>
                </template>
            </PageHeader>

            <div class="space-y-3">
                <UiCard v-for="service in services" :key="service.id">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="`/admin/services/${service.id}/edit`"
                                    class="text-base font-semibold hover:underline"
                                >
                                    {{ service.title }}
                                </Link>
                                <UiBadge size="sm">{{ service.type }}</UiBadge>
                                <UiBadge :tone="service.isPublished ? 'success' : 'neutral'" size="sm" dot>
                                    {{ service.isPublished ? 'Live' : 'Draft' }}
                                </UiBadge>
                            </div>

                            <p class="mt-1 text-sm" style="color: var(--text-muted)">{{ service.tagline }}</p>
                            <p class="mt-2 text-xs" style="color: var(--text-muted)">
                                {{ service.deliverableCount }} deliverables · {{ service.modelCount }} engagement models
                                · updated {{ service.updatedAt }}
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-2">
                            <UiButton
                                v-if="service.isPublished"
                                :href="`/services/${service.slug}`"
                                :inertia="false"
                                variant="ghost"
                                size="xs"
                                icon
                                aria-label="View on the site"
                            >
                                <ExternalLink class="h-3.5 w-3.5" />
                            </UiButton>
                            <UiButton :href="`/admin/services/${service.id}/edit`" variant="secondary" size="xs">
                                <template #leading><Pencil class="h-3 w-3" /></template>
                                Edit
                            </UiButton>
                        </div>
                    </div>
                </UiCard>
            </div>
        </div>
    </AppLayout>
</template>
