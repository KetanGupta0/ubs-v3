<script setup>
/**
 * A statement of account (CL14): a window of dates, the opening balance,
 * every invoice and everything that settled it, and the running balance.
 * It is a document rather than a list, so it reads in date order and is
 * downloaded whole, as a PDF or a spreadsheet.
 */
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { Download, FileSpreadsheet } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';

const props = defineProps({
    statement: { type: Object, required: true },
    /** The page's own address, without a query. */
    base: { type: String, required: true },
});

const window = reactive({ from: props.statement.from, to: props.statement.to });

const query = (format = null) => new URLSearchParams({ from: window.from, to: window.to, ...(format ? { format } : {}) }).toString();

function show() {
    router.get(props.base, { from: window.from, to: window.to }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
    <div class="space-y-5">
        <UiCard>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="show">
                <UiFormField label="From">
                    <UiInput v-model="window.from" type="date" />
                </UiFormField>
                <UiFormField label="To">
                    <UiInput v-model="window.to" type="date" />
                </UiFormField>
                <UiButton type="submit" variant="secondary">Show</UiButton>
                <span class="flex-1" />
                <UiButton :href="`${base}?${query('pdf')}`" :inertia="false" variant="ghost" size="sm">
                    <template #leading><Download class="h-3.5 w-3.5" /></template>
                    PDF
                </UiButton>
                <UiButton :href="`${base}?${query('csv')}`" :inertia="false" variant="ghost" size="sm">
                    <template #leading><FileSpreadsheet class="h-3.5 w-3.5" /></template>
                    Spreadsheet
                </UiButton>
            </form>
        </UiCard>

        <div class="grid gap-3 sm:grid-cols-3">
            <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Opening, {{ statement.fromLabel }}</p><p class="mt-1 text-lg font-semibold tnum">{{ statement.opening }}</p></UiCard>
            <UiCard padding="p-4"><p class="text-xs" style="color: var(--text-muted)">Billed · settled</p><p class="mt-1 text-lg font-semibold tnum">{{ statement.debits }} · {{ statement.credits }}</p></UiCard>
            <UiCard padding="p-4">
                <p class="text-xs" style="color: var(--text-muted)">Closing, {{ statement.toLabel }}</p>
                <p class="mt-1 text-lg font-semibold tnum">{{ statement.closing }}</p>
                <p class="text-xs" style="color: var(--text-muted)">{{ statement.closingOwed ? 'Due' : 'Nothing due' }}</p>
            </UiCard>
        </div>

        <UiCard>
            <p v-if="!statement.entries.length" class="text-sm" style="color: var(--text-muted)">Nothing was billed or settled in these dates.</p>
            <div v-else class="-mx-5 overflow-x-auto scrollbar-thin sm:mx-0">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide" style="color: var(--text-muted)">
                            <th class="px-3 py-2 font-medium">Date</th>
                            <th class="px-3 py-2 font-medium">Reference</th>
                            <th class="px-3 py-2 font-medium">Description</th>
                            <th class="px-3 py-2 text-right font-medium">Debit</th>
                            <th class="px-3 py-2 text-right font-medium">Credit</th>
                            <th class="px-3 py-2 text-right font-medium">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(entry, index) in statement.entries" :key="index">
                            <td class="whitespace-nowrap px-3 py-2">{{ entry.date }}</td>
                            <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ entry.reference }}</td>
                            <td class="px-3 py-2">{{ entry.description }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tnum">{{ entry.debit }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right tnum">{{ entry.credit }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-right font-medium tnum">{{ entry.balance }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </UiCard>
    </div>
</template>
