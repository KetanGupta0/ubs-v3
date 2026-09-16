<script setup>
/**
 * Who changed what.
 *
 * Read only, with the before and after available on a row rather than in a
 * separate screen, because the useful question is almost always "what did that
 * one change actually do".
 */
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { ChevronDown } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiModal from '@/components/UI/UiModal.vue';

defineProps({
    table: { type: Object, required: true },
});

const inspecting = ref(null);

function display(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (Array.isArray(value)) return value.join(', ') || '—';

    return String(value);
}

/*
 * Most entries are a before and after pair. A few, such as a permission
 * change, record only what the action did, so those show in the after column
 * with nothing to compare against.
 */
function changeRows(changes) {
    return Object.entries(changes ?? {}).map(([field, change]) => {
        const paired = change && typeof change === 'object' && !Array.isArray(change)
            && ('from' in change || 'to' in change);

        return {
            field: field.replace(/_/g, ' '),
            from: paired ? display(change.from) : '—',
            to: paired ? display(change.to) : display(change),
        };
    });
}
</script>

<template>
    <Head title="Audit log" />

    <AppLayout title="Audit log" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Audit log' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Audit log"
                description="Every change made from the admin panel, with the account that made it. Nothing here can be edited or removed from inside the application."
            />

            <DataTable
                :table="table"
                search-placeholder="Search by action or record…"
                empty-title="Nothing recorded yet"
                empty-description="Entries appear as soon as somebody changes something."
                :only="['table']"
            >
                <template #cell:action="{ row }">
                    <code class="rounded bg-[var(--surface-sunken)] px-1.5 py-0.5 text-xs">{{ row.action }}</code>
                </template>

                <template #cell:summary="{ row }">
                    <button
                        v-if="row.changes && Object.keys(row.changes).length"
                        type="button"
                        class="inline-flex items-center gap-1 text-left underline decoration-dotted underline-offset-2"
                        @click="inspecting = row"
                    >
                        {{ row.summary }}
                        <ChevronDown class="h-3 w-3" />
                    </button>
                    <span v-else>{{ row.summary }}</span>
                </template>

                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <code class="text-xs">{{ row.action }}</code>
                                <span class="mt-1 block truncate text-sm font-medium">{{ row.label }}</span>
                            </span>
                            <UiBadge size="sm">{{ row.actor }}</UiBadge>
                        </div>
                        <p class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs" style="color: var(--text-muted)">
                            <span>{{ row.created_at }}</span>
                            <UiButton
                                v-if="row.changes && Object.keys(row.changes).length"
                                variant="ghost"
                                size="xs"
                                @click="inspecting = row"
                            >
                                What changed
                            </UiButton>
                        </p>
                    </div>
                </template>
            </DataTable>
        </div>

        <UiModal
            :open="Boolean(inspecting)"
            :title="inspecting ? inspecting.action : ''"
            :description="inspecting ? `${inspecting.actor} · ${inspecting.created_at}` : ''"
            size="lg"
            @close="inspecting = null"
        >
            <div v-if="inspecting" class="space-y-4">
                <p v-if="inspecting.label !== '—'" class="text-sm">
                    <span style="color: var(--text-muted)">Record:</span>
                    <span class="font-medium">{{ inspecting.subject }} — {{ inspecting.label }}</span>
                </p>

                <div class="overflow-x-auto scrollbar-thin">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs uppercase tracking-wide" style="border-color: var(--border-subtle); color: var(--text-muted)">
                                <th class="py-2 pr-4 font-medium">Field</th>
                                <th class="py-2 pr-4 font-medium">Was</th>
                                <th class="py-2 font-medium">Became</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="change in changeRows(inspecting.changes)"
                                :key="change.field"
                                class="border-b last:border-0"
                                style="border-color: var(--border-subtle)"
                            >
                                <td class="py-2 pr-4 align-top capitalize">{{ change.field }}</td>
                                <td class="py-2 pr-4 align-top" style="color: var(--text-muted)">{{ change.from }}</td>
                                <td class="py-2 align-top font-medium">{{ change.to }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="inspecting.ip_address" class="text-xs" style="color: var(--text-muted)">
                    From {{ inspecting.ip_address }}
                </p>
            </div>

            <template #footer>
                <UiButton variant="ghost" @click="inspecting = null">Close</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
