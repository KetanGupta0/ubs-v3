<script setup>
/**
 * Messages members reported in their batch groups. A person decides: remove
 * it, stop its sender posting for a while, or dismiss the report.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Check, MessagesSquare, MicOff, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

defineProps({
    table: { type: Object, required: true },
    waiting: { type: Number, default: 0 },
    silenceOptions: { type: Array, default: () => [] },
});

const tones = { open: 'warning', actioned: 'success', dismissed: 'neutral' };
const keep = { preserveScroll: true };

function remove(row) {
    if (window.confirm(`Remove this message from ${row.group} for everybody?`)) {
        router.post(`/admin/chat/reports/${row.id}/remove`, {}, keep);
    }
}

const dismiss = (row) => router.post(`/admin/chat/reports/${row.id}/dismiss`, {}, keep);

const silencing = ref(null);
const silenceForm = useForm({ for: '1d', reason: '' });

function openSilence(row) {
    silencing.value = row;
    blankForm(silenceForm, { for: '1d', reason: '' });
}

const silence = () => silenceForm.post(`/admin/chat/reports/${silencing.value.id}/silence`, { ...keep, onSuccess: () => { silencing.value = null; } });
</script>

<template>
    <Head title="Reported messages" />

    <AppLayout title="Reported messages" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Reported messages' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Reported messages"
                description="Messages members reported in their batch groups. Nothing is taken down until somebody here decides."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Messages waiting" :value="waiting" :tone="waiting ? 'warning' : 'neutral'" href="/admin/chat/reports?filter[status]=open" />
            </div>

            <DataTable
                :table="table"
                empty-title="Nothing reported"
                empty-description="Clear the filter to see reports already dealt with."
                :only="['table', 'waiting']"
            >
                <template #cell:message="{ row }">
                    <span class="block max-w-md whitespace-pre-line break-words" :style="row.removed ? { color: 'var(--text-muted)', fontStyle: 'italic' } : {}">{{ row.message }}</span>
                </template>
                <template #cell:group="{ row }">
                    <Link :href="`/chat/${row.conversationId}`" class="inline-flex items-center gap-1 hover:underline">
                        <MessagesSquare class="h-3.5 w-3.5" style="color: var(--text-muted)" />{{ row.group }}
                    </Link>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                    <span v-if="row.handledBy" class="block text-xs" style="color: var(--text-muted)">{{ row.handledBy }}</span>
                </template>
                <template #rowActions="{ row }">
                    <div v-if="row.status === 'open' && row.canModerate" class="flex flex-wrap justify-end gap-1">
                        <UiButton v-if="!row.removed" variant="ghost" size="xs" @click="remove(row)">
                            <template #leading><Trash2 class="h-3.5 w-3.5" /></template>
                            Remove
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="openSilence(row)">
                            <template #leading><MicOff class="h-3.5 w-3.5" /></template>
                            Stop posting
                        </UiButton>
                        <UiButton variant="ghost" size="xs" @click="dismiss(row)">
                            <template #leading><Check class="h-3.5 w-3.5" /></template>
                            Dismiss
                        </UiButton>
                    </div>
                </template>
                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.sender }} · {{ row.group }}</span>
                            <UiBadge :tone="tones[row.status] ?? 'neutral'" size="sm" dot>{{ row.statusLabel }}</UiBadge>
                        </div>
                        <p class="mt-1 line-clamp-3 text-sm">{{ row.message }}</p>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">Reported by {{ row.reporter }} · {{ row.created_at }}</p>
                        <div v-if="row.status === 'open' && row.canModerate" class="mt-2 flex flex-wrap gap-1">
                            <UiButton v-if="!row.removed" variant="secondary" size="xs" @click="remove(row)">Remove</UiButton>
                            <UiButton variant="secondary" size="xs" @click="openSilence(row)">Stop posting</UiButton>
                            <UiButton variant="ghost" size="xs" @click="dismiss(row)">Dismiss</UiButton>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>

        <UiModal :open="silencing !== null" :title="silencing ? `Stop ${silencing.sender} posting in ${silencing.group}` : ''" description="They can still read the group, and they are told until when." @close="silencing = null">
            <form id="silence-report-form" class="space-y-3" @submit.prevent="silence">
                <UiFormField label="For" :error="silenceForm.errors.for">
                    <UiSelect v-model="silenceForm.for" :options="silenceOptions" />
                </UiFormField>
                <UiFormField label="Reason" hint="Optional. Sent to them, and kept on the record." :error="silenceForm.errors.reason">
                    <UiTextarea v-model="silenceForm.reason" :rows="2" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="silencing = null">Cancel</UiButton>
                <UiButton type="submit" form="silence-report-form" :loading="silenceForm.processing">Stop them posting</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
