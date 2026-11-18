<script setup>
/**
 * Everything the platform has told this person, newest first. Opening one
 * marks it read and goes where it points.
 */
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { CheckCheck } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

defineProps({
    table: { type: Object, required: true },
});

const page = usePage();
const viewingAs = computed(() => Boolean(page.props.viewingAs));
const unread = computed(() => Number(page.props.auth?.unreadNotifications ?? 0));

function open(row) {
    if (viewingAs.value || !row.unread) {
        if (row.url) router.visit(row.url);
        return;
    }
    router.post(`/notifications/${row.id}`);
}

const readAll = () => router.post('/notifications/read-all', {}, { preserveScroll: true });
</script>

<template>
    <Head title="Notifications" />

    <AppLayout title="Notifications" :breadcrumbs="[{ label: 'Notifications' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader title="Notifications" description="What we have told you here. The same things also come by email, unless they are only for this screen.">
                <template #actions>
                    <UiButton v-if="unread && !viewingAs" size="sm" variant="secondary" @click="readAll">
                        <template #leading><CheckCheck class="h-3.5 w-3.5" /></template>
                        Mark all read
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" empty-title="Nothing yet" empty-description="When something needs you, or there is news, it appears here.">
                <template #cell:title="{ row }">
                    <button type="button" class="text-left hover:underline" :style="{ color: 'var(--text-strong)', fontWeight: row.unread ? 600 : 400 }" @click="open(row)">{{ row.title }}</button>
                </template>
                <template #cell:read="{ row }">
                    <UiBadge :tone="row.unread ? 'brand' : 'neutral'" size="sm" dot>{{ row.unread ? 'New' : 'Read' }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <button type="button" class="block w-full text-left" @click="open(row)">
                        <span class="block text-sm" :style="{ fontWeight: row.unread ? 600 : 400 }">{{ row.title }}</span>
                        <span class="mt-1 block text-xs" style="color: var(--text-muted)">{{ row.created_at }}</span>
                    </button>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
