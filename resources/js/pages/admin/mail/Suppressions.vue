<script setup>
/**
 * The do-not-mail list (§8): nobody here gets greetings or announcements, and
 * everybody here still gets the mail their account needs. Until a mail
 * provider reports bounces (X8), an address that bounced is added by hand.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Undo2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    table: { type: Object, required: true },
    reasons: { type: Array, required: true },
});

const open = ref(false);
const form = useForm({ email: '', reason: 'bounced', note: '' });

function start() {
    blankForm(form, { email: '', reason: 'bounced', note: '' });
    open.value = true;
}

function save() {
    form.post('/admin/mail/suppressions', { preserveScroll: true, onSuccess: () => (open.value = false) });
}

function allow(row) {
    if (window.confirm(`Send greetings and announcements to ${row.email} again?`)) {
        router.delete(`/admin/mail/suppressions/${row.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Do not mail" />

    <AppLayout title="Do not mail" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Announcements', href: '/admin/mail/broadcasts' }, { label: 'Do not mail' }]">
        <div>
            <PageHeader title="Do not mail" description="No greetings or announcements go to these addresses. Messages about an account still do: sign in codes, receipts, reminders.">
                <template #actions>
                    <UiButton size="sm" @click="start">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Add an address
                    </UiButton>
                </template>
            </PageHeader>

            <DataTable :table="table" search-placeholder="Search by address…" empty-title="Nobody on the list" empty-description="Somebody who stops these from a mail, or turns them off in their account, appears here.">
                <template #rowActions="{ row }">
                    <UiButton size="xs" variant="ghost" icon :aria-label="`Mail ${row.email} again`" @click="allow(row)"><Undo2 class="h-3.5 w-3.5" /></UiButton>
                </template>
                <template #mobileRow="{ row }">
                    <p class="truncate text-sm font-medium">{{ row.email }}</p>
                    <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.reason }} · since {{ row.created_at }}</p>
                </template>
            </DataTable>
        </div>

        <UiModal :open="open" title="Add an address" @close="open = false">
            <form id="suppression-form" class="space-y-4" @submit.prevent="save">
                <UiFormField label="Address" required :error="form.errors.email">
                    <UiInput v-model="form.email" type="email" />
                </UiFormField>
                <UiFormField label="Why" required :error="form.errors.reason">
                    <UiSelect v-model="form.reason" :options="reasons" />
                </UiFormField>
                <UiFormField label="Note" :error="form.errors.note">
                    <UiInput v-model="form.note" maxlength="300" placeholder="Mailbox does not exist, from the bounce on 12 Nov" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton type="submit" form="suppression-form" :loading="form.processing">Add</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
