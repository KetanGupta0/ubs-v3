<script setup>
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import MenuEditor from '@/components/Admin/MenuEditor.vue';

defineProps({
    menus: { type: Array, required: true },
    suggestions: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Site menus" />

    <AppLayout title="Site menus" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Site menus' }]">
        <div class="mx-auto max-w-4xl">
            <PageHeader
                title="Site menus"
                description="The links in the public site's header and footer. Each menu saves on its own and is live on every page straight away. Published legal documents are always listed at the end of the footer, so they are not here."
            />

            <div class="space-y-5">
                <MenuEditor v-for="menu in menus" :key="menu.key" :menu="menu" :suggestions="suggestions" />
            </div>
        </div>
    </AppLayout>
</template>
