<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Plus, RefreshCw, Ban, Pencil, KeyRound } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
    clients: { type: Array, default: () => [] },
    intervals: { type: Array, default: () => [] },
});

const open = ref(false);
const editing = ref(null);

const form = useForm({
    client_id: null,
    name: '',
    description: '',
    amount: null,
    interval: 'yearly',
    starts_on: '',
    renews_on: '',
    auto_renew: false,
    status: 'active',
});

function start(row = null) {
    editing.value = row;
    form.clearErrors();
    form.client_id = row?.clientId ?? null;
    form.name = row?.name ?? '';
    form.description = row?.description ?? '';
    form.amount = row?.amountRaw ?? null;
    form.interval = row?.intervalValue ?? 'yearly';
    form.starts_on = row?.startsOnValue ?? '';
    form.renews_on = row?.renewsOnValue ?? '';
    form.auto_renew = row?.autoRenew ?? false;
    form.status = row?.status ?? 'active';
    open.value = true;
}

function save() {
    const done = { preserveScroll: true, onSuccess: () => finish() };

    editing.value
        ? form.put(`/admin/subscriptions/${editing.value.id}`, done)
        : form.post('/admin/subscriptions', done);
}

function finish() {
    open.value = false;
    editing.value = null;
    form.reset();
}

function renew(row) {
    if (confirm(`Roll ${row.name} forward by one ${row.interval.toLowerCase()} period?`)) {
        router.post(`/admin/subscriptions/${row.id}/renew`, {}, { preserveScroll: true });
    }
}

function cancel(row) {
    if (confirm(`Cancel ${row.name}?`)) {
        router.post(`/admin/subscriptions/${row.id}/cancel`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Subscriptions" />

    <AppLayout title="Subscriptions" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Subscriptions' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Subscriptions"
                description="Everything that renews: hosting, maintenance, licences and API plans."
            >
                <template #actions>
                    <UiButton href="/admin/api-plans" variant="secondary" size="sm">
                        <template #leading><KeyRound class="h-3.5 w-3.5" /></template>
                        API plans
                    </UiButton>
                    <UiButton size="sm" @click="start()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Add a subscription
                    </UiButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid gap-3 sm:grid-cols-4">
                <StatTile label="Active" :value="counts.active ?? 0" tone="success" />
                <StatTile
                    label="Renewing within 30 days"
                    :value="counts.renewingSoon ?? 0"
                    :tone="counts.renewingSoon ? 'warning' : 'neutral'"
                />
                <StatTile label="Lapsed" :value="counts.lapsed ?? 0" :tone="counts.lapsed ? 'danger' : 'neutral'" />
                <StatTile label="Recurring value" :value="counts.value ?? '₹0.00'" />
            </div>

            <DataTable
                :table="table"
                search-placeholder="Search subscriptions…"
                empty-title="No subscriptions match"
                empty-description="Try clearing the filters, or add one."
                :only="['table']"
            >
                <template #cell:renews_on="{ row }">
                    <span :style="row.lapsed ? { color: 'var(--color-danger-500)' } : row.daysToRenewal <= 30 ? { color: 'var(--color-warn-600)' } : {}">
                        {{ row.renews_on }}
                    </span>
                </template>

                <template #cell:status="{ row }">
                    <UiBadge
                        :tone="row.lapsed ? 'danger' : row.status === 'active' ? 'success' : 'neutral'"
                        size="sm"
                        dot
                    >
                        {{ row.lapsed ? 'lapsed' : row.status }}
                    </UiBadge>
                </template>

                <template #rowActions="{ row }">
                    <span class="flex justify-end gap-0.5">
                        <UiButton variant="ghost" size="xs" icon aria-label="Edit" @click="start(row)">
                            <Pencil class="h-3 w-3" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Renew" @click="renew(row)">
                            <RefreshCw class="h-3 w-3" />
                        </UiButton>
                        <UiButton variant="ghost" size="xs" icon aria-label="Cancel" @click="cancel(row)">
                            <Ban class="h-3 w-3" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </span>
                </template>

                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{ row.name }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ row.client }}</span>
                            </span>
                            <span class="text-right">
                                <span class="block text-sm font-semibold tnum">{{ row.amount }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ row.interval }}</span>
                            </span>
                        </div>
                        <p class="mt-2 flex items-center gap-2 text-xs" style="color: var(--text-muted)">
                            <span>Renews {{ row.renews_on }}</span>
                            <UiBadge v-if="row.autoRenew" size="sm">auto</UiBadge>
                        </p>
                    </div>
                </template>
            </DataTable>
        </div>

        <UiModal
            :open="open"
            :title="editing ? 'Edit subscription' : 'Add a subscription'"
            size="lg"
            @close="finish"
        >
            <form id="subscription-form" class="space-y-4" @submit.prevent="save">
                <UiFormField label="Client" required :error="form.errors.client_id">
                    <UiCombobox v-model="form.client_id" :options="clients" placeholder="Search clients…" />
                </UiFormField>

                <UiFormField label="What renews" required :error="form.errors.name">
                    <UiInput v-model="form.name" placeholder="Managed hosting, production" />
                </UiFormField>

                <UiFormField label="Description" :error="form.errors.description">
                    <UiTextarea v-model="form.description" :rows="2" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Amount (₹)" required :error="form.errors.amount">
                        <UiInput v-model="form.amount" type="number" step="0.01" min="0" />
                    </UiFormField>

                    <UiFormField label="Every" required :error="form.errors.interval">
                        <UiSelect v-model="form.interval" :options="intervals" />
                    </UiFormField>

                    <UiFormField label="Started" required :error="form.errors.starts_on">
                        <UiDateInput v-model="form.starts_on" />
                    </UiFormField>

                    <UiFormField label="Next renewal" required :error="form.errors.renews_on">
                        <UiDateInput v-model="form.renews_on" />
                    </UiFormField>

                    <UiFormField label="Status" required :error="form.errors.status">
                        <UiSelect
                            v-model="form.status"
                            :options="[
                                { value: 'active', label: 'Active' },
                                { value: 'paused', label: 'Paused' },
                                { value: 'cancelled', label: 'Cancelled' },
                            ]"
                        />
                    </UiFormField>
                </div>

                <UiSwitch
                    v-model="form.auto_renew"
                    label="Renews automatically"
                    description="The client can change this themselves."
                />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="finish">Cancel</UiButton>
                <UiButton type="submit" form="subscription-form" :loading="form.processing">
                    {{ editing ? 'Save' : 'Add subscription' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
