<script setup>
/**
 * Orders from the shop (§4). Money is taken and refunded in Billing like any
 * other payment; an order says what it was for and what it gave.
 */
import { Head, Link } from '@inertiajs/vue3';
import { BadgeCheck, TicketPercent } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, required: true },
});

const tone = (key) => ({ paid: 'success', pending: 'warning', cancelled: 'neutral', refunded: 'danger', partially_refunded: 'info' })[key] ?? 'neutral';
</script>

<template>
    <Head title="Shop orders" />

    <AppLayout title="Shop orders" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Shop orders' }]">
        <div>
            <PageHeader title="Shop orders" :description="`Bought from the cart. ${counts.paidThisMonth} sold this month before tax; ${counts.pending} waiting for payment.`">
                <template #actions>
                    <UiButton href="/admin/licences" variant="ghost" size="sm">
                        <template #leading><BadgeCheck class="h-3.5 w-3.5" /></template>
                        Licences
                    </UiButton>
                    <UiButton href="/admin/reports/sales" variant="ghost" size="sm">Sales report</UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by order number…" empty-title="No orders yet" empty-description="Orders appear here once somebody checks out from the cart.">
                <template #cell:number="{ row }">
                    <Link :href="`/admin/orders/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.number }}</Link>
                </template>
                <template #cell:client="{ row }">
                    <span>{{ row.client }}</span>
                    <span v-if="row.buyer" class="block text-xs" style="color: var(--text-muted)">{{ row.buyer }}</span>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/orders/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold">{{ row.number }}</span>
                            <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.client }} · {{ row.summary }} · {{ row.total }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
