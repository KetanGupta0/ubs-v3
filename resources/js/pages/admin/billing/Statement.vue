<script setup>
/** A client organisation's statement of account, from our side (CL14). */
import { Head } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatementView from '@/components/Billing/StatementView.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    client: { type: Object, required: true },
    statement: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`Statement · ${client.name}`" />

    <AppLayout :title="`Statement · ${client.name}`" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Clients', href: '/admin/clients' }, { label: client.name, href: `/admin/clients/${client.id}` }, { label: 'Statement' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader :title="`Statement of account · ${client.name}`" description="The same statement the client downloads.">
                <template #actions>
                    <UiButton :href="`/admin/clients/${client.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        {{ client.name }}
                    </UiButton>
                </template>
            </PageHeader>
            <StatementView :statement="statement" :base="`/admin/clients/${client.id}/statement`" />
        </div>
    </AppLayout>
</template>
