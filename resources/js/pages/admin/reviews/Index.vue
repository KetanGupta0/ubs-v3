<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Check, EyeOff, MessageSquareReply, Quote, Star } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    canModerate: { type: Boolean, default: false },
});

const statusTones = { pending: 'warning', approved: 'success', hidden: 'neutral' };

const current = ref(null);
const replyForm = useForm({ reply: '' });

function open(row) {
    current.value = row;
    replyForm.reply = row.reply ?? '';
    replyForm.clearErrors();
}

const close = () => { current.value = null; };
const keep = { preserveScroll: true, onSuccess: close };

const setStatus = (status) => router.put(`/admin/reviews/${current.value.id}/status`, { status }, keep);
const saveReply = () => replyForm.put(`/admin/reviews/${current.value.id}/reply`, keep);
const promote = () => router.post(`/admin/reviews/${current.value.id}/testimonial`, {}, keep);
</script>

<template>
    <Head title="Reviews" />

    <AppLayout title="Reviews" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Reviews' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Reviews"
                description="What students say about their courses. Nothing shows to anybody else until it is approved, and nothing reaches the public site unless its author agreed."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Waiting to be read" :value="counts.pending ?? 0" :tone="counts.pending ? 'warning' : 'neutral'" />
                <StatTile label="On the public site" :value="counts.public ?? 0" tone="success" />
                <StatTile label="Average approved rating" :value="counts.average ? `${counts.average} / 5` : '—'" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search reviews, students or courses…"
                empty-title="No reviews match"
                empty-description="Try clearing the filters."
                :only="['table', 'counts']"
            >
                <template #cell:student="{ row }">
                    <span class="block font-medium" style="color: var(--text-strong)">{{ row.student }}</span>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.email }}</span>
                </template>

                <template #cell:rating="{ row }">
                    <span class="inline-flex items-center gap-0.5" :aria-label="`${row.rating} out of 5`">
                        <Star
                            v-for="n in 5"
                            :key="n"
                            class="h-3.5 w-3.5"
                            :style="{ color: n <= row.rating ? 'var(--color-warn-500)' : 'var(--border-strong)' }"
                            :fill="n <= row.rating ? 'currentColor' : 'none'"
                            aria-hidden="true"
                        />
                    </span>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <UiButton variant="ghost" size="xs" @click="open(row)">{{ canModerate ? 'Read and moderate' : 'Read' }}</UiButton>
                </template>

                <template #mobileRow="{ row }">
                    <button type="button" class="block w-full text-left" @click="open(row)">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.student }} · {{ row.rating }}/5</span>
                            <UiBadge :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-1 truncate text-xs" style="color: var(--text-muted)">{{ row.course }}</p>
                        <p class="mt-1.5 line-clamp-2 text-sm">{{ row.review }}</p>
                    </button>
                </template>
            </DataTable>
        </div>

        <UiModal
            :open="current !== null"
            :title="current ? `${current.student} on ${current.course}` : ''"
            :description="current ? `${current.rating} out of 5 · written ${current.created_at}` : ''"
            size="lg"
            @close="close"
        >
            <div v-if="current" class="space-y-5">
                <div>
                    <p v-if="current.title" class="font-medium" style="color: var(--text-strong)">{{ current.title }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ current.body }}</p>
                    <p class="mt-2 text-xs" style="color: var(--text-muted)">
                        {{ current.mayPublish ? 'The author agreed to it appearing on the public site.' : 'The author did not agree to the public site. If approved, only students see it.' }}
                    </p>
                </div>

                <div v-if="canModerate" class="flex flex-wrap gap-2">
                    <UiButton v-if="current.status !== 'approved'" size="sm" @click="setStatus('approved')">
                        <template #leading><Check class="h-3.5 w-3.5" /></template>
                        Approve
                    </UiButton>
                    <UiButton v-if="current.status !== 'hidden'" variant="secondary" size="sm" @click="setStatus('hidden')">
                        <template #leading><EyeOff class="h-3.5 w-3.5" /></template>
                        Hide
                    </UiButton>
                    <UiButton
                        v-if="current.status === 'approved' && current.mayPublish"
                        variant="ghost"
                        size="sm"
                        @click="promote"
                    >
                        <template #leading><Quote class="h-3.5 w-3.5" /></template>
                        Use as a homepage testimonial
                    </UiButton>
                </div>

                <form v-if="canModerate" class="space-y-3" @submit.prevent="saveReply">
                    <UiFormField label="Reply" hint="Shown under the review, wherever it appears. Leave empty for no reply." :error="replyForm.errors.reply">
                        <UiTextarea v-model="replyForm.reply" :rows="3" />
                    </UiFormField>
                    <UiButton type="submit" variant="secondary" size="sm" :loading="replyForm.processing">
                        <template #leading><MessageSquareReply class="h-3.5 w-3.5" /></template>
                        Save reply
                    </UiButton>
                </form>
                <p v-else-if="current.reply" class="rounded-lg px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                    {{ current.reply }}
                </p>
            </div>

            <template #footer>
                <UiButton variant="ghost" @click="close">Close</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
