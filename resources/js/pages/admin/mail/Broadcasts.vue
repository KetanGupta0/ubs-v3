<script setup>
/**
 * Everything sent as greetings and announcements (§8), with the day's budget
 * and the switch that lets people who only sent an enquiry be mailed (X19).
 */
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MailX, PartyPopper, Plus } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    table: { type: Object, required: true },
    budget: { type: Object, required: true },
    enquiries: { type: Object, required: true },
});

const tone = (key) => ({ draft: 'neutral', scheduled: 'info', sending: 'warning', sent: 'success', cancelled: 'neutral' })[key] ?? 'neutral';

const confirming = ref(false);
const switchForm = useForm({ on: false, confirm: false });

function setEnquiries(on) {
    switchForm.on = on;
    switchForm.post('/admin/mail/enquiries', { preserveScroll: true, onSuccess: () => { confirming.value = false; switchForm.reset(); } });
}
</script>

<template>
    <Head title="Announcements" />

    <AppLayout title="Announcements" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Announcements' }]">
        <div class="space-y-5">
            <PageHeader title="Announcements and greetings" :description="`Everything sent by email to students, clients and enquiry senders. ${budget.promotionalLeft} more can go today: they stop once the day's mail leaves only the ${budget.reserve} kept for sign in codes, receipts and reminders, and the rest waits for tomorrow.`">
                <template #actions>
                    <UiButton href="/admin/mail/occasions" variant="ghost" size="sm">
                        <template #leading><PartyPopper class="h-3.5 w-3.5" /></template>
                        Greeting days
                    </UiButton>
                    <UiButton href="/admin/mail/suppressions" variant="ghost" size="sm">
                        <template #leading><MailX class="h-3.5 w-3.5" /></template>
                        Do not mail
                    </UiButton>
                    <UiButton href="/admin/mail/broadcasts/new" size="sm">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        New announcement
                    </UiButton>
                </template>
            </PageHeader>

            <UiCard>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">People who only sent an enquiry</p>
                        <p v-if="enquiries.allowed" class="mt-1 text-sm" style="color: var(--text-muted)">Included in greetings and announcements, with a way to stop them in every mail.</p>
                        <p v-else-if="!enquiries.policyAllows" class="mt-1 text-sm" style="color: var(--text-muted)">
                            Left out. The published privacy policy promises not to add them to a mailing list; publish the version that says we send these first, under
                            <Link href="/admin/legal" class="underline">Legal documents</Link>.
                        </p>
                        <p v-else class="mt-1 text-sm" style="color: var(--text-muted)">Left out. The privacy policy now says we send these, so they can be included.</p>
                    </div>
                    <UiButton v-if="enquiries.allowed" size="sm" variant="secondary" :loading="switchForm.processing" @click="setEnquiries(false)">Leave them out</UiButton>
                    <UiButton v-else-if="enquiries.policyAllows && !confirming" size="sm" variant="secondary" @click="confirming = true">Include them</UiButton>
                </div>
                <form v-if="confirming" class="mt-4 space-y-3" @submit.prevent="setEnquiries(true)">
                    <UiFormField :error="switchForm.errors.confirm">
                        <UiCheckbox v-model="switchForm.confirm" label="The published privacy policy tells them we send greetings and announcements, and how to stop them." />
                    </UiFormField>
                    <div class="flex gap-2">
                        <UiButton type="submit" size="sm" :loading="switchForm.processing">Include them</UiButton>
                        <UiButton size="sm" variant="ghost" @click="confirming = false">Not now</UiButton>
                    </div>
                </form>
            </UiCard>

            <DataTable :table="table" search-placeholder="Search by subject…" empty-title="Nothing sent yet" empty-description="Write an announcement, or switch on a greeting day.">
                <template #cell:subject="{ row }">
                    <Link :href="`/admin/mail/broadcasts/${row.id}`" class="font-medium hover:underline" style="color: var(--text-strong)">{{ row.subject }}</Link>
                    <span v-if="row.by" class="block text-xs" style="color: var(--text-muted)">by {{ row.by }}</span>
                </template>
                <template #cell:status="{ row }">
                    <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                </template>
                <template #mobileRow="{ row }">
                    <Link :href="`/admin/mail/broadcasts/${row.id}`" class="block">
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm font-semibold">{{ row.subject }}</span>
                            <UiBadge :tone="tone(row.statusKey)" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <p class="mt-1 text-xs" style="color: var(--text-muted)">{{ row.kind }} · {{ row.when }} · sent {{ row.reach }}</p>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
