<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({ table: { type: Object, required: true } });
</script>

<template>
    <Head title="Support" />

    <AppLayout title="Support" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Support' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader title="Support" description="Ask us about a course, a class, your fees or the site. A person reads every ticket and replies here, and by email.">
                <template #actions>
                    <UiButton href="/student/support/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New ticket
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search your tickets…" empty-title="No tickets yet" empty-description="If something is not working, raise one with New ticket.">
                <template #cell:subject="{ row }">
                    <Link :href="`/student/support/${row.id}`" class="block hover:underline">
                        <span class="font-medium" style="color: var(--text-strong)">{{ row.subject }}</span>
                        <span class="block text-xs" style="color: var(--text-muted)">{{ row.reference }}</span>
                    </Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="row.status === 'waiting_on_client' ? 'warning' : row.open ? 'brand' : 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
