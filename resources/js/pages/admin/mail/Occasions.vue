<script setup>
/**
 * The days worth a greeting (§8, X7), and the greeting for each. A national
 * day keeps its date; a festival's date moves, so it is confirmed each year,
 * and an unconfirmed one does not go. A greeting goes on the day only.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarCheck, Eye, Pencil, Plus, Send, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    occasions: { type: Array, default: () => [] },
    groups: { type: Array, required: true },
    repeats: { type: Array, required: true },
    media: { type: Array, default: () => [] },
    enquiriesAllowed: { type: Boolean, default: false },
    budget: { type: Object, required: true },
});

const blank = {
    name: '', date: '', repeats: 'confirm_yearly', date_confirmed: false, send_time: '08:00', audience: ['students', 'clients', 'enquiries'],
    subject: '', message: '', media_id: '', is_active: false,
};
const open = ref(false);
const editing = ref(null);
const form = useForm(structuredClone(blank));

const fields = (row) => ({
    name: row.name, date: row.date ?? '', repeats: row.repeats, date_confirmed: row.date_confirmed, send_time: row.send_time,
    audience: [...row.audience], subject: row.subject, message: row.message, media_id: row.media_id ?? '', is_active: row.is_active,
});

function start(row = null) {
    editing.value = row;
    blankForm(form, row ? fields(row) : blank);
    open.value = true;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => (open.value = false) };
    const data = (values) => ({ ...values, date: values.date || null, media_id: values.media_id || null });

    editing.value ? form.transform(data).put(`/admin/mail/occasions/${editing.value.id}`, options) : form.transform(data).post('/admin/mail/occasions', options);
}

function update(row, changes) {
    router.put(`/admin/mail/occasions/${row.id}`, { ...fields(row), media_id: row.media_id || null, date: row.date || null, ...changes }, { preserveScroll: true });
}

function remove(row) {
    if (window.confirm(`Remove ${row.name}?`)) router.delete(`/admin/mail/occasions/${row.id}`, { preserveScroll: true });
}

function test(row) {
    router.post(`/admin/mail/occasions/${row.id}/test`, {}, { preserveScroll: true });
}

const previewUrl = ref(null);

const state = (row) => {
    if (!row.is_active) return ['Off', 'neutral'];
    if (!row.date) return ['No date', 'warning'];
    if (!row.date_confirmed) return ['Date to confirm', 'warning'];
    return ['On', 'success'];
};
const when = (row) => (row.daysAway === 0 ? 'today' : row.daysAway === 1 ? 'tomorrow' : row.daysAway > 1 ? `in ${row.daysAway} days` : '');
const groupLabel = (value) => props.groups.find((group) => group.value === value)?.label ?? value;
</script>

<template>
    <Head title="Greetings" />

    <AppLayout title="Greetings" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Announcements', href: '/admin/mail/broadcasts' }, { label: 'Greetings' }]">
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader title="Greeting days" :description="`A greeting goes on its day from its time, in everybody's notifications at once and by mail within the day's budget (at most ${Math.max(0, budget.limit - budget.reserve)} a day, fewer on a busy day), so on a big day not everybody gets the mail. A festival's date is confirmed each year.`">
                <template #actions>
                    <UiButton size="sm" @click="start()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Add a day
                    </UiButton>
                </template>
            </PageHeader>

            <p v-if="!enquiriesAllowed" class="rounded-[var(--radius-control)] p-3 text-sm" style="background: var(--surface-sunken)">
                People who only sent an enquiry are left out of every greeting until the privacy policy saying we send these is published and they are switched on under Announcements.
            </p>

            <UiCard v-if="!occasions.length">
                <p class="text-sm" style="color: var(--text-muted)">No days yet.</p>
            </UiCard>

            <UiCard v-for="row in occasions" :key="row.id">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-base font-semibold">
                            {{ row.name }}
                            <UiBadge :tone="state(row)[1]" size="sm" dot>{{ state(row)[0] }}</UiBadge>
                        </p>
                        <p class="mt-0.5 text-sm" style="color: var(--text-muted)">
                            <template v-if="row.dateLabel">{{ row.dateLabel }} at {{ row.send_time }}<template v-if="when(row)">, {{ when(row) }}</template></template>
                            <template v-else>No date set</template>
                            · {{ row.repeats === 'same_date' ? 'same date every year' : 'date confirmed each year' }}
                            · to {{ row.audience.map(groupLabel).join(', ').toLowerCase() }}
                        </p>
                        <p class="mt-2 text-sm"><span class="font-medium">{{ row.subject }}</span></p>
                        <p v-if="row.lastSentOn" class="mt-1 text-xs" style="color: var(--text-muted)">Last sent {{ row.lastSentOn }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <UiButton v-if="row.date && !row.date_confirmed" size="xs" variant="secondary" @click="update(row, { date_confirmed: true })">
                            <template #leading><CalendarCheck class="h-3 w-3" /></template>
                            {{ row.dateLabel }} is right
                        </UiButton>
                        <UiButton size="xs" variant="ghost" @click="update(row, { is_active: !row.is_active })">{{ row.is_active ? 'Switch off' : 'Switch on' }}</UiButton>
                        <UiButton size="xs" variant="ghost" icon :aria-label="`Preview ${row.name}`" @click="previewUrl = `/admin/mail/occasions/${row.id}/preview`"><Eye class="h-3 w-3" /></UiButton>
                        <UiButton size="xs" variant="ghost" icon :aria-label="`Send ${row.name} to me`" @click="test(row)"><Send class="h-3 w-3" /></UiButton>
                        <UiButton size="xs" variant="ghost" icon :aria-label="`Change ${row.name}`" @click="start(row)"><Pencil class="h-3 w-3" /></UiButton>
                        <UiButton v-if="row.sends === 0" size="xs" variant="ghost" icon :aria-label="`Remove ${row.name}`" @click="remove(row)"><Trash2 class="h-3 w-3" /></UiButton>
                    </div>
                </div>
            </UiCard>
        </div>

        <UiModal :open="open" :title="editing ? `Change ${editing.name}` : 'Add a day'" size="lg" @close="open = false">
            <form id="occasion-form" class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Name" required :error="form.errors.name">
                        <UiInput v-model="form.name" maxlength="120" placeholder="Diwali" />
                    </UiFormField>
                    <UiFormField label="Its date" hint="The next time it falls." :error="form.errors.date">
                        <UiInput v-model="form.date" type="date" />
                    </UiFormField>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Each year" required :error="form.errors.repeats">
                        <UiSelect v-model="form.repeats" :options="repeats" />
                    </UiFormField>
                    <UiFormField label="Sent at" required hint="On the day, from this time." :error="form.errors.send_time">
                        <UiInput v-model="form.send_time" type="time" />
                    </UiFormField>
                </div>
                <UiSwitch v-if="form.repeats === 'confirm_yearly'" v-model="form.date_confirmed" label="The date is confirmed" description="Without this the greeting does not go. We ask a month before." />
                <UiFormField label="To" required :error="form.errors.audience">
                    <div class="space-y-2">
                        <div v-for="group in groups" :key="group.value"><UiCheckbox v-model="form.audience" :value="group.value" :label="group.label" /></div>
                    </div>
                </UiFormField>
                <UiFormField label="Subject" required hint="{first_name} and {name} are filled in for each reader." :error="form.errors.subject">
                    <UiInput v-model="form.subject" maxlength="200" placeholder="Happy Diwali, {first_name}" />
                </UiFormField>
                <UiFormField label="Message" required hint="Markdown: blank lines between paragraphs." :error="form.errors.message">
                    <UiTextarea v-model="form.message" :rows="5" />
                </UiFormField>
                <UiFormField label="Picture" hint="From the media library." :error="form.errors.media_id">
                    <UiSelect v-model="form.media_id" :options="[{ value: '', label: 'No picture' }, ...media]" />
                </UiFormField>
                <UiSwitch v-model="form.is_active" label="On" description="Off, the day stays here and nothing is sent." />
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton type="submit" form="occasion-form" :loading="form.processing">Save</UiButton>
            </template>
        </UiModal>

        <UiModal :open="previewUrl !== null" title="Preview" size="lg" @close="previewUrl = null">
            <iframe v-if="previewUrl" :src="previewUrl" title="The greeting as it will look" class="h-[70vh] w-full rounded-lg border bg-white" style="border-color: var(--border-subtle)" sandbox="" />
        </UiModal>
    </AppLayout>
</template>
