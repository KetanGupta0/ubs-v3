<script setup>
/**
 * What we watch for clients so nothing lapses (CL16): domains, hosting,
 * certificates, update windows. Reminders go at 30, 14 and 3 days; a lapsed
 * domain or certificate is an emergency, so our side hears too.
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarCheck, Pencil, Plus, XCircle } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    table: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
    projects: { type: Array, default: () => [] },
});

const blank = { client_id: null, project_id: '', kind: 'domain', name: '', provider: '', expires_on: '', auto_renew: false, notes: '' };
const editing = ref(null);
const open = ref(false);
const form = useForm(structuredClone(blank));
const projectOptions = computed(() => [{ value: '', label: 'Not tied to a project' }, ...props.projects.filter((project) => project.clientId === form.client_id)]);

function start(row = null) {
    editing.value = row;
    blankForm(form, row ? {
        client_id: row.clientId, project_id: row.projectId ?? '', kind: row.kindValue, name: row.name, provider: row.provider ?? '',
        expires_on: row.expiresOn, auto_renew: row.autoRenew, notes: row.notes ?? '',
    } : blank);
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const data = (values) => ({ ...values, project_id: values.project_id || null });

    editing.value ? form.transform(data).put(`/admin/renewals/${editing.value.id}`, options) : form.transform(data).post('/admin/renewals', options);
}

function renewed(row) {
    const date = window.prompt(`${row.name} was renewed. Until when? (YYYY-MM-DD)`, '');
    if (!date) return;
    router.put(`/admin/renewals/${row.id}`, {
        client_id: row.clientId, project_id: row.projectId, kind: row.kindValue, name: row.name, provider: row.provider,
        expires_on: date, auto_renew: row.autoRenew, notes: row.notes,
    }, { preserveScroll: true });
}

function stop(row) {
    if (window.confirm(`Stop watching ${row.name}? No more reminders will go out for it.`)) router.post(`/admin/renewals/${row.id}/cancel`, {}, { preserveScroll: true });
}

const tone = (row) => (row.status !== 'active' ? 'neutral' : row.days < 0 ? 'danger' : row.days <= 14 ? 'warning' : 'success');
const label = (row) => (row.status !== 'active' ? 'Not watching' : row.days < 0 ? 'Lapsed' : row.days === 0 ? 'Lapses today' : `${row.days} days`);
</script>

<template>
    <Head title="Renewals" />

    <AppLayout title="Renewals" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Renewals' }]">
        <div>
            <PageHeader title="Renewals" description="Domains, hosting, certificates and update windows we keep an eye on for clients. They and we are reminded at 30, 14 and 3 days; contracts and subscriptions are reminded about from their own screens.">
                <template #actions>
                    <UiButton size="sm" @click="start()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Watch something
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by name or provider…" empty-title="Nothing watched yet" empty-description="Add a client's domain, hosting or certificate so it never lapses unnoticed.">
                <template #cell:expires_on="{ row }">
                    <span>{{ row.expires_on }}</span>
                    <UiBadge class="ml-2" :tone="tone(row)" size="sm" dot>{{ label(row) }}</UiBadge>
                </template>
                <template #cell:status="{ row }">
                    <div v-if="row.status === 'active'" class="flex gap-1">
                        <UiButton size="xs" variant="secondary" @click="renewed(row)">
                            <template #leading><CalendarCheck class="h-3 w-3" /></template>
                            Renewed
                        </UiButton>
                        <UiButton size="xs" variant="ghost" icon aria-label="Edit" @click="start(row)"><Pencil class="h-3 w-3" /></UiButton>
                        <UiButton size="xs" variant="ghost" icon aria-label="Stop watching" @click="stop(row)"><XCircle class="h-3 w-3" /></UiButton>
                    </div>
                    <span v-else class="text-xs" style="color: var(--text-muted)">Not watching</span>
                </template>
                <template #mobileRow="{ row }">
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-semibold">{{ row.name }}</span>
                        <UiBadge :tone="tone(row)" size="sm" dot>{{ label(row) }}</UiBadge>
                    </div>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.kind }} · {{ row.client }} · {{ row.expires_on }}</p>
                </template>
            </DataTable>
        </div>

        <UiModal :open="open" :title="editing ? `Change ${editing.name}` : 'Watch something'" @close="open = false">
            <form id="renewal-form" class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Client" required :error="form.errors.client_id">
                        <UiCombobox v-model="form.client_id" :options="clients" placeholder="Choose the organisation" />
                    </UiFormField>
                    <UiFormField label="Project" :error="form.errors.project_id">
                        <UiSelect v-model="form.project_id" :options="projectOptions" :disabled="!form.client_id" />
                    </UiFormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Kind" required :error="form.errors.kind">
                        <UiSelect v-model="form.kind" :options="kinds" />
                    </UiFormField>
                    <UiFormField label="Lapses on" required :error="form.errors.expires_on">
                        <UiInput v-model="form.expires_on" type="date" />
                    </UiFormField>
                </div>
                <UiFormField label="Name" required :error="form.errors.name">
                    <UiInput v-model="form.name" maxlength="160" placeholder="acmelogistics.in" />
                </UiFormField>
                <UiFormField label="Provider" :error="form.errors.provider">
                    <UiInput v-model="form.provider" maxlength="120" placeholder="Registrar, host or certificate authority" />
                </UiFormField>
                <UiSwitch v-model="form.auto_renew" label="Renews automatically" description="Reminders still go, to check the payment details on file." />
                <UiFormField label="Notes" hint="Never a password: say where access is kept instead." :error="form.errors.notes">
                    <UiTextarea v-model="form.notes" :rows="3" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton type="submit" form="renewal-form" :loading="form.processing">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
