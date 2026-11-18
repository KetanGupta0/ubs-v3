<script setup>
/**
 * Every licence sold (CL4). The key is never shown here: only its holder
 * ever saw it, and they can find theirs by the first group, which is what a
 * search here takes.
 */
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CalendarPlus, RotateCcw, ShieldOff } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import { blankForm } from '@/support/forms';

defineProps({
    table: { type: Object, required: true },
    mayManage: { type: Boolean, default: false },
});

const extending = ref(null);
const extendForm = useForm({ updates_until: '', support_until: '', reason: '' });

function startExtend(row) {
    extending.value = row;
    blankForm(extendForm, { updates_until: row.updatesUntilValue, support_until: row.supportUntilValue ?? '', reason: '' });
}

function extend() {
    extendForm
        .transform((data) => ({ ...data, support_until: data.support_until || null }))
        .put(`/admin/licences/${extending.value.id}/extend`, { preserveScroll: true, onSuccess: () => (extending.value = null) });
}

function revoke(row) {
    const reason = window.prompt(`Withdraw ${row.product} for ${row.client}? Say why: the holder sees it.`);
    if (reason) router.post(`/admin/licences/${row.id}/revoke`, { reason }, { preserveScroll: true });
}

function restore(row) {
    if (window.confirm(`Restore ${row.product} for ${row.client}? Its key works again.`)) router.post(`/admin/licences/${row.id}/restore`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Licences" />

    <AppLayout title="Licences" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Shop orders', href: '/admin/orders' }, { label: 'Licences' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader title="Licences" description="Source code licences sold through the shop: the sites using each, when its updates end, and the last time its software checked in. Search by the key's first group, such as UBS-7K3QX." />

            <DataTable :table="table" search-placeholder="Search by product or key prefix…" empty-title="No licences yet" empty-description="A licence is issued when an order for source code is paid.">
                <template #cell:product="{ row }">
                    <Link :href="`/admin/orders/${row.orderId}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.product }}</Link>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.order }} · {{ row.downloads }} {{ row.downloads === 1 ? 'download' : 'downloads' }}</span>
                </template>
                <template #cell:key="{ row }">
                    <span class="whitespace-nowrap font-mono text-xs">{{ row.key }}</span>
                </template>
                <template #cell:status="{ row }">
                    <div class="flex items-center gap-1">
                        <UiBadge :tone="row.active ? 'success' : 'danger'" size="sm" dot>{{ row.status }}</UiBadge>
                        <template v-if="mayManage">
                            <UiButton v-if="row.active" size="xs" variant="ghost" icon :aria-label="`Extend ${row.product}`" @click="startExtend(row)"><CalendarPlus class="h-3 w-3" /></UiButton>
                            <UiButton v-if="row.active" size="xs" variant="ghost" icon :aria-label="`Withdraw ${row.product}`" @click="revoke(row)"><ShieldOff class="h-3 w-3" /></UiButton>
                            <UiButton v-else size="xs" variant="ghost" icon :aria-label="`Restore ${row.product}`" @click="restore(row)"><RotateCcw class="h-3 w-3" /></UiButton>
                        </template>
                    </div>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/orders/${row.orderId}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.product }}</span>
                            <UiBadge :tone="row.active ? 'success' : 'danger'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.client }} · {{ row.sites }} sites · updates until {{ row.updates_until }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>

        <UiModal :open="extending !== null" :title="extending ? `Extend ${extending.product}` : ''" @close="extending = null">
            <form id="extend-form" class="space-y-4" @submit.prevent="extend">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Updates until" required :error="extendForm.errors.updates_until">
                        <UiInput v-model="extendForm.updates_until" type="date" />
                    </UiFormField>
                    <UiFormField label="Support until" :error="extendForm.errors.support_until">
                        <UiInput v-model="extendForm.support_until" type="date" />
                    </UiFormField>
                </div>
                <UiFormField label="Why" required hint="Kept in the audit log." :error="extendForm.errors.reason">
                    <UiInput v-model="extendForm.reason" maxlength="300" placeholder="Agreed when the project was handed over" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="extending = null">Cancel</UiButton>
                <UiButton type="submit" form="extend-form" :loading="extendForm.processing">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
