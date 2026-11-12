<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, EyeOff, Eye, Send } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    doubt: { type: Object, required: true },
});

const tones = { open: 'warning', answered: 'success', hidden: 'neutral' };
const form = useForm({ body: '' });

const answer = () => form.post(`/admin/doubts/${props.doubt.id}/reply`, { preserveScroll: true, onSuccess: () => form.reset() });

function setHidden(hidden) {
    if (!hidden || window.confirm('Take this question down? The batch stops seeing it; the thread is kept.')) {
        router.put(`/admin/doubts/${props.doubt.id}/visibility`, { hidden }, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`Question on ${doubt.lesson}`" />

    <AppLayout
        :title="`Question on ${doubt.lesson}`"
        :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Questions', href: '/admin/doubts?filter[status]=open' }, { label: doubt.lesson }]"
    >
        <div class="mx-auto max-w-3xl space-y-5">
            <PageHeader :title="doubt.lesson" :description="`${doubt.course}${doubt.batch ? ` · ${doubt.batch}` : ''}`">
                <template #actions>
                    <UiButton href="/admin/doubts?filter[status]=open" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Questions
                    </UiButton>
                    <UiButton v-if="doubt.status !== 'hidden'" variant="ghost" size="sm" @click="setHidden(true)">
                        <template #leading><EyeOff class="h-3.5 w-3.5" /></template>
                        Take down
                    </UiButton>
                    <UiButton v-else variant="secondary" size="sm" @click="setHidden(false)">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Put back
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-center justify-between gap-2 text-xs" style="color: var(--text-muted)">
                    <span>
                        <a :href="`/admin/students/${doubt.askerId}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ doubt.asker }}</a>
                        asked on {{ doubt.at }}
                    </span>
                    <UiBadge :tone="tones[doubt.status] ?? 'neutral'" size="sm" dot>{{ doubt.statusLabel }}</UiBadge>
                </div>
                <p class="mt-3 whitespace-pre-line text-sm">{{ doubt.body }}</p>

                <ul v-if="doubt.replies.length" class="mt-4 space-y-3 border-l-2 pl-4" style="border-color: var(--border-subtle)">
                    <li v-for="reply in doubt.replies" :key="reply.id">
                        <p class="text-xs" style="color: var(--text-muted)">
                            <span class="font-medium" :class="reply.fromStaff ? 'text-brand-600 dark:text-brand-400' : ''">{{ reply.author }}</span>
                            {{ reply.fromStaff ? '· team' : '· student' }} · {{ reply.at }}
                        </p>
                        <p class="mt-0.5 whitespace-pre-line text-sm">{{ reply.body }}</p>
                    </li>
                </ul>
            </UiCard>

            <UiCard v-if="doubt.status !== 'hidden'">
                <form class="space-y-3" @submit.prevent="answer">
                    <UiFormField label="Your answer" hint="Shown under the lesson to everybody on the batch, with your name." :error="form.errors.body">
                        <UiTextarea v-model="form.body" :rows="5" />
                    </UiFormField>
                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="form.processing" :disabled="!form.body.trim()">
                            <template #leading><Send class="h-3.5 w-3.5" /></template>
                            Answer
                        </UiButton>
                    </div>
                </form>
            </UiCard>
        </div>
    </AppLayout>
</template>
