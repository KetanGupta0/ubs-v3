<script setup>
/**
 * Discount codes (§4). What a code is, here; whether it applies is decided on
 * the server when a cart is priced and again when the order is placed. A code
 * somebody used keeps its name and stays on record: switch it off instead.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Power, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    table: { type: Object, required: true },
    solutions: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
});

const blank = {
    code: '', description: '', kind: 'percent', percent: '', amount: '', max_discount: '', min_order: '',
    starts_at: '', ends_at: '', max_uses: '', max_uses_per_account: 1, solution_ids: [], category_ids: [],
    first_order_only: false, stackable: false, is_active: true,
};
const kinds = [
    { value: 'percent', label: 'A percentage' },
    { value: 'amount', label: 'An amount in rupees' },
];

const open = ref(false);
const editing = ref(null);
const form = useForm(structuredClone(blank));
const restricted = computed(() => form.solution_ids.length > 0 || form.category_ids.length > 0);

function start(row = null) {
    editing.value = row;
    blankForm(form, row ? {
        code: row.code, description: row.description ?? '', kind: row.kind, percent: row.percent ?? '', amount: row.amount ?? '',
        max_discount: row.max_discount ?? '', min_order: row.min_order ?? '', starts_at: row.starts_at ?? '', ends_at: row.ends_at ?? '',
        max_uses: row.max_uses ?? '', max_uses_per_account: row.max_uses_per_account ?? '', solution_ids: [...row.solution_ids],
        category_ids: [...row.category_ids], first_order_only: row.first_order_only, stackable: row.stackable, is_active: row.is_active,
    } : blank);
    open.value = true;
}

function save() {
    const empty = (value) => (value === '' ? null : value);
    const data = (values) => Object.fromEntries(Object.entries(values).map(([key, value]) => [key, empty(value)]));
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };

    editing.value ? form.transform(data).put(`/admin/coupons/${editing.value.id}`, options) : form.transform(data).post('/admin/coupons', options);
}

function toggle(row) {
    router.post(`/admin/coupons/${row.id}/toggle`, {}, { preserveScroll: true });
}

function remove(row) {
    if (window.confirm(`Delete ${row.code}?`)) router.delete(`/admin/coupons/${row.id}`, { preserveScroll: true });
}

const tone = (state) => ({ On: 'success', Off: 'neutral', Ended: 'neutral', 'Not started': 'info', 'Used up': 'warning' })[state] ?? 'neutral';
</script>

<template>
    <Head title="Discount codes" />

    <AppLayout title="Discount codes" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Discount codes' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader title="Discount codes" description="Codes buyers type in the cart. Each is checked when the cart is priced and again when the order is placed, and every use is kept against its order.">
                <template #actions>
                    <UiButton size="sm" @click="start()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New code
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search codes…" empty-title="No codes yet" empty-description="Make one for a launch, a festival or a first order.">
                <template #cell:code="{ row }">
                    <span class="font-mono font-semibold">{{ row.code }}</span>
                    <span v-if="row.description" class="block text-xs" style="color: var(--text-muted)">{{ row.description }}</span>
                </template>
                <template #cell:state="{ row }">
                    <div class="flex items-center gap-1">
                        <UiBadge :tone="tone(row.state)" size="sm" dot>{{ row.state }}</UiBadge>
                        <UiButton size="xs" variant="ghost" icon :aria-label="`Change ${row.code}`" @click="start(row)"><Pencil class="h-3 w-3" /></UiButton>
                        <UiButton size="xs" variant="ghost" icon :aria-label="row.is_active ? `Switch ${row.code} off` : `Switch ${row.code} on`" @click="toggle(row)"><Power class="h-3 w-3" /></UiButton>
                        <UiButton v-if="row.usedCount === 0" size="xs" variant="ghost" icon :aria-label="`Delete ${row.code}`" @click="remove(row)"><Trash2 class="h-3 w-3" /></UiButton>
                    </div>
                </template>
                <template #mobileRow="{ row }">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-sm font-semibold">{{ row.code }}</span>
                        <UiBadge :tone="tone(row.state)" size="sm" dot>{{ row.state }}</UiBadge>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.value }} · {{ row.applies }} · used {{ row.used }}</p>
                </template>
            </DataTable>
        </div>

        <UiModal :open="open" :title="editing ? `Change ${editing.code}` : 'New code'" @close="open = false">
            <form id="coupon-form" class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Code" required hint="Letters, digits, dashes. Buyers type it in any case." :error="form.errors.code">
                        <UiInput v-model="form.code" maxlength="40" class="font-mono uppercase" placeholder="DIWALI10" />
                    </UiFormField>
                    <UiFormField label="Shown to the buyer" :error="form.errors.description">
                        <UiInput v-model="form.description" maxlength="200" placeholder="Festival offer, 10% off" />
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Takes off" required :error="form.errors.kind">
                        <UiSelect v-model="form.kind" :options="kinds" />
                    </UiFormField>
                    <UiFormField v-if="form.kind === 'percent'" label="Percent" required :error="form.errors.percent">
                        <UiInput v-model="form.percent" type="number" min="1" max="100" />
                    </UiFormField>
                    <UiFormField v-else label="Rupees" required :error="form.errors.amount">
                        <UiInput v-model="form.amount" type="number" min="1" step="0.01" />
                    </UiFormField>
                    <UiFormField v-if="form.kind === 'percent'" label="At most, rupees" :error="form.errors.max_discount">
                        <UiInput v-model="form.max_discount" type="number" min="1" step="0.01" />
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Order of at least" hint="Before tax, in rupees." :error="form.errors.min_order">
                        <UiInput v-model="form.min_order" type="number" min="1" step="0.01" />
                    </UiFormField>
                    <UiFormField label="Uses in all" :error="form.errors.max_uses">
                        <UiInput v-model="form.max_uses" type="number" min="1" placeholder="No limit" />
                    </UiFormField>
                    <UiFormField label="Uses per buyer" :error="form.errors.max_uses_per_account">
                        <UiInput v-model="form.max_uses_per_account" type="number" min="1" placeholder="No limit" />
                    </UiFormField>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="From" :error="form.errors.starts_at">
                        <UiInput v-model="form.starts_at" type="datetime-local" />
                    </UiFormField>
                    <UiFormField label="Until" :error="form.errors.ends_at">
                        <UiInput v-model="form.ends_at" type="datetime-local" />
                    </UiFormField>
                </div>

                <UiFormField label="Only on" :hint="restricted ? 'Takes off only what is in these.' : 'Nothing ticked: everything sold.'" :error="form.errors.solution_ids">
                    <div class="max-h-40 space-y-1.5 overflow-y-auto rounded-[var(--radius-control)] border p-3" style="border-color: var(--border-subtle)">
                        <p v-if="categories.length" class="text-xs font-medium" style="color: var(--text-muted)">Categories</p>
                        <UiCheckbox v-for="category in categories" :key="`c${category.value}`" v-model="form.category_ids" :value="category.value" :label="category.label" class="flex" />
                        <p v-if="solutions.length" class="pt-1 text-xs font-medium" style="color: var(--text-muted)">Products</p>
                        <UiCheckbox v-for="solution in solutions" :key="`s${solution.value}`" v-model="form.solution_ids" :value="solution.value" :label="solution.label" class="flex" />
                    </div>
                </UiFormField>

                <div class="space-y-3">
                    <UiSwitch v-model="form.first_order_only" label="First order only" description="For an organisation that has never paid for an order." />
                    <UiSwitch v-model="form.stackable" label="Can be combined" description="With other codes that can be combined. Each takes off what is left after the one before." />
                    <UiSwitch v-model="form.is_active" label="On" />
                </div>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton type="submit" form="coupon-form" :loading="form.processing">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
