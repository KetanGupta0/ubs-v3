<script setup>
/**
 * What the organisation bought from the shop (CL5): every order, what was in
 * it and where it stands. Licences, keys and downloads are on each order.
 */
import { Head, Link } from '@inertiajs/vue3';
import { ShoppingCart } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
});

const tone = (key) => ({ paid: 'success', pending: 'warning', cancelled: 'neutral', refunded: 'neutral', partially_refunded: 'info' })[key] ?? 'neutral';
</script>

<template>
    <Head title="Orders" />

    <AppLayout title="Orders" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Orders' }]">
        <div>
            <PageHeader title="Orders" description="What your organisation bought from our shop: licences and downloads for source code, hosted plans and add-ons, each with its invoice.">
                <template #actions>
                    <UiButton href="/solutions" :inertia="false" variant="secondary" size="sm">
                        <template #leading><ShoppingCart class="h-3.5 w-3.5" /></template>
                        Shop
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by order number…" empty-title="No orders yet" empty-description="Products sold with their source code can be bought from their page in the catalogue.">
                <template #cell:number="{ row }">
                    <Link :href="`/client/orders/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.number }}</Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/client/orders/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold">{{ row.number }}</span>
                            <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.summary }} · {{ row.total }} · {{ row.created_at }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
