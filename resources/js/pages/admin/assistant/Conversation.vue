<script setup>
/** One visitor's conversation with the website assistant, with the tools each answer used (§9.2). */
import { Head } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';

defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, default: () => [] },
    gaps: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`Conversation · ${conversation.started}`" />

    <AppLayout title="Conversation" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Website assistant', href: '/admin/assistant' }, { label: conversation.started }]">
        <div class="space-y-5">
            <PageHeader
                :title="`Started ${conversation.started}`"
                :description="`${conversation.page ? `On ${conversation.page}. ` : ''}${conversation.outcome ? `${conversation.outcome}. ` : ''}${conversation.tokens.toLocaleString('en-IN')} tokens. Deleted on ${conversation.deletes}.`"
            />

            <UiCard v-if="conversation.lead">
                <p class="text-sm">It became a lead: <a :href="`/admin/leads/${conversation.lead.id}`" class="font-medium underline">{{ conversation.lead.name }}</a>.</p>
            </UiCard>

            <UiCard>
                <ol class="space-y-4">
                    <li v-for="message in messages" :key="message.id">
                        <p class="text-xs font-semibold" style="color: var(--text-muted)">{{ message.role === 'visitor' ? 'Visitor' : 'Assistant' }} · {{ message.at }}</p>
                        <p v-if="message.text" class="mt-1 whitespace-pre-line text-sm">{{ message.text }}</p>
                        <!-- eslint-disable-next-line vue/no-v-html -- made safe on the server -->
                        <div v-else class="assistant-reply mt-1 text-sm" v-html="message.html" />
                        <p v-for="tool in message.tools" :key="tool" class="mt-1 break-all font-mono text-[11px]" style="color: var(--text-muted)">↳ {{ tool }}</p>
                    </li>
                </ol>
            </UiCard>

            <UiCard v-if="gaps.length">
                <p class="text-sm font-semibold">It could not answer</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    <li v-for="gap in gaps" :key="gap">{{ gap }}</li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>

<style scoped>
.assistant-reply :deep(a) {
    color: var(--text-brand);
    text-decoration: underline;
}
.assistant-reply :deep(ul) {
    list-style: disc;
    padding-left: 1.1rem;
}
</style>
