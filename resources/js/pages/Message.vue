<script setup>
/**
 * A greeting or an announcement, read in the platform (§8), with the switch
 * that stops them, by mail and here alike.
 */
import { Head, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import MarkdownHtml from '@/components/Marketing/MarkdownHtml.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';

defineProps({
    message: { type: Object, required: true },
    receiving: { type: Boolean, default: true },
});
</script>

<template>
    <Head :title="message.subject" />

    <AppLayout :title="message.subject" :breadcrumbs="[{ label: 'Messages' }, { label: message.date }]">
        <div class="space-y-5">
            <UiCard>
                <p class="text-xs" style="color: var(--text-muted)">{{ message.date }}</p>
                <h1 class="mt-1 text-xl font-semibold">{{ message.subject }}</h1>
                <img v-if="message.image" :src="message.image" :alt="message.imageAlt ?? ''" class="mt-4 w-full rounded-xl" />
                <MarkdownHtml class="mt-4" :html="message.html" />
            </UiCard>

            <UiCard>
                <UiSwitch
                    :model-value="receiving"
                    label="Greetings and announcements"
                    description="By email and here. Messages about your account still come whatever you choose."
                    @update:model-value="(receive) => router.put('/settings/mail', { receive }, { preserveScroll: true })"
                />
            </UiCard>
        </div>
    </AppLayout>
</template>
