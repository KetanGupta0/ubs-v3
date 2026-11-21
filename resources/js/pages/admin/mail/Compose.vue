<script setup>
/**
 * Writing an announcement (§8): the words, a picture, who it is for with a
 * live count, a preview and a test to yourself, then send now, at a time, or
 * keep it as a draft. It reaches everybody with an account in the platform at
 * once; the mail goes out a few at a time inside the day's budget.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, Send, Users } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import FormSection from '@/components/Admin/FormSection.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { debounce } from '@/support/debounce';
import { toast } from '@/support/toast';

const props = defineProps({
    campaign: { type: Object, default: null },
    groups: { type: Array, required: true },
    courses: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    colleges: { type: Array, default: () => [] },
    interests: { type: Array, default: () => [] },
    media: { type: Array, default: () => [] },
    enquiries: { type: Object, required: true },
});

const blankAudience = { groups: ['students'], course_id: '', batch_id: '', college_id: '', enrolment_status: '', lead_interest: '', joined_from: '', joined_to: '', never_signed_in: false };

const form = useForm({
    subject: props.campaign?.subject ?? '',
    message: props.campaign?.message ?? '',
    media_id: props.campaign?.media_id ?? '',
    audience: { ...blankAudience, ...(props.campaign?.audience ?? {}), course_id: props.campaign?.audience?.course_id ?? '', batch_id: props.campaign?.audience?.batch_id ?? '', college_id: props.campaign?.audience?.college_id ?? '' },
    action: 'send',
    scheduled_at: props.campaign?.scheduled_at ?? '',
});

const groupOptions = computed(() => props.groups.map((group) => ({ ...group, disabled: group.value === 'enquiries' && !props.enquiries.allowed })));
const withStudents = computed(() => form.audience.groups.includes('students'));
const withEnquiries = computed(() => form.audience.groups.includes('enquiries'));
const picture = computed(() => props.media.find((item) => item.value === Number(form.media_id)) ?? null);
const statuses = [
    { value: '', label: 'Any' }, { value: 'active', label: 'Studying now' }, { value: 'completed', label: 'Completed' },
    { value: 'on_hold', label: 'On hold' }, { value: 'dropped', label: 'Dropped' },
];
const any = (list) => [{ value: '', label: 'Any' }, ...list];

/* ------------------------------------------------------- the live count */

const count = ref(null);
const counting = ref(false);
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const recount = debounce(async () => {
    counting.value = true;
    try {
        const response = await fetch('/admin/mail/audience/count', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ audience: form.audience }),
        });
        count.value = response.ok ? await response.json() : null;
    } finally {
        counting.value = false;
    }
}, 400);

watch(() => form.audience, recount, { deep: true });
onMounted(recount);

/* ----------------------------------------------------- preview and test */

const previewing = ref(false);
const previewHtml = ref('');

async function post(url) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/html,application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf() },
        credentials: 'same-origin',
        body: JSON.stringify({ subject: form.subject, message: form.message, media_id: form.media_id || null }),
    });
}

async function preview() {
    if (!form.subject || !form.message) {
        toast.info('Write a subject and a message first.');
        return;
    }
    const response = await post('/admin/mail/preview');
    if (!response.ok) {
        toast.error('The preview could not be made.');
        return;
    }
    previewHtml.value = await response.text();
    previewing.value = true;
}

const testing = ref(false);
function test() {
    if (!form.subject || !form.message) {
        toast.info('Write a subject and a message first.');
        return;
    }
    testing.value = true;
    form.transform((data) => ({ subject: data.subject, message: data.message, media_id: data.media_id || null }))
        .post('/admin/mail/test', { preserveScroll: true, onFinish: () => { testing.value = false; form.transform((data) => data); } });
}

/* ------------------------------------------------------------- sending */

function submit(action) {
    if (action === 'send' && !window.confirm(`Send to ${count.value?.reachable ?? 'everybody chosen'}? It is in their notifications at once; the mail goes out within the day's budget.`)) {
        return;
    }

    form.transform((data) => ({
        ...data,
        action,
        media_id: data.media_id || null,
        scheduled_at: action === 'schedule' ? data.scheduled_at : null,
        audience: Object.fromEntries(Object.entries(data.audience).map(([key, value]) => [key, value === '' ? null : value])),
    }));

    props.campaign
        ? form.put(`/admin/mail/broadcasts/${props.campaign.id}`, { preserveScroll: true })
        : form.post('/admin/mail/broadcasts', { preserveScroll: true });
}
</script>

<template>
    <Head :title="campaign ? 'Change the announcement' : 'New announcement'" />

    <AppLayout :title="campaign ? 'Change the announcement' : 'New announcement'" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Announcements', href: '/admin/mail/broadcasts' }, { label: campaign ? 'Change' : 'New' }]">
        <div class="space-y-6">
            <PageHeader :title="campaign ? 'Change the announcement' : 'New announcement'" description="Nobody gets more than a couple of these a week, and every one says how to stop them.">
                <template #actions>
                    <UiButton href="/admin/mail/broadcasts" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Announcements
                    </UiButton>
                </template>
            </PageHeader>

            <FormSection title="What it says" description="Write {first_name} or {name} where the reader's name should go. The message is Markdown: blank lines between paragraphs, **bold**, lists and links.">
                <UiFormField label="Subject" required :error="form.errors.subject">
                    <UiInput v-model="form.subject" maxlength="200" placeholder="New batches of the Laravel course, {first_name}" />
                </UiFormField>
                <UiFormField label="Message" required :error="form.errors.message">
                    <UiTextarea v-model="form.message" :rows="10" />
                </UiFormField>
                <UiFormField label="Picture" hint="From the media library, shown above the message." :error="form.errors.media_id">
                    <UiSelect v-model="form.media_id" :options="[{ value: '', label: 'No picture' }, ...media]" />
                </UiFormField>
                <img v-if="picture" :src="picture.url" :alt="picture.label" class="max-h-40 rounded-lg border" style="border-color: var(--border-subtle)" />
                <div class="flex flex-wrap gap-2">
                    <UiButton variant="secondary" size="sm" @click="preview">
                        <template #leading><Eye class="h-3.5 w-3.5" /></template>
                        Preview
                    </UiButton>
                    <UiButton variant="secondary" size="sm" :loading="testing" @click="test">Send a test to me</UiButton>
                </div>
            </FormSection>

            <FormSection title="Who it is for" description="Staff never get these, and neither does anybody on the do-not-mail list.">
                <UiFormField label="People" required :error="form.errors['audience.groups'] || form.errors.audience">
                    <div class="space-y-2">
                        <div v-for="group in groupOptions" :key="group.value"><UiCheckbox v-model="form.audience.groups" :value="group.value" :label="group.label" :disabled="group.disabled" /></div>
                    </div>
                </UiFormField>
                <p v-if="!enquiries.allowed" class="text-xs" style="color: var(--text-muted)">People who only sent an enquiry are left out until the privacy policy saying we send these is published and they are switched on under Announcements.</p>

                <div v-if="withStudents" class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Students on the course">
                        <UiSelect v-model="form.audience.course_id" :options="any(courses)" />
                    </UiFormField>
                    <UiFormField label="Students in the batch">
                        <UiSelect v-model="form.audience.batch_id" :options="any(batches)" />
                    </UiFormField>
                    <UiFormField label="Students from the college">
                        <UiSelect v-model="form.audience.college_id" :options="any(colleges)" />
                    </UiFormField>
                    <UiFormField label="Whose enrolment is">
                        <UiSelect v-model="form.audience.enrolment_status" :options="statuses" />
                    </UiFormField>
                </div>
                <UiFormField v-if="withEnquiries" label="Enquiries about">
                    <UiSelect v-model="form.audience.lead_interest" :options="any(interests)" />
                </UiFormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Joined or wrote on or after">
                        <UiInput v-model="form.audience.joined_from" type="date" />
                    </UiFormField>
                    <UiFormField label="Joined or wrote on or before">
                        <UiInput v-model="form.audience.joined_to" type="date" />
                    </UiFormField>
                </div>
                <UiSwitch v-model="form.audience.never_signed_in" label="Only accounts that have never signed in" description="To bring back people who never started." />

                <p class="flex items-center gap-2 rounded-[var(--radius-control)] p-3 text-sm" style="background: var(--surface-sunken)">
                    <Users class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                    <span v-if="counting && !count">Counting…</span>
                    <span v-else-if="count">
                        <strong class="tnum">{{ count.reachable }}</strong> {{ count.reachable === 1 ? 'person' : 'people' }}
                        <template v-if="count.suppressed"> ({{ count.suppressed }} more on the do-not-mail list are left out)</template>
                    </span>
                </p>
            </FormSection>

            <FormSection title="When">
                <UiFormField label="At a time" hint="Leave empty to send now or keep a draft." :error="form.errors.scheduled_at">
                    <UiInput v-model="form.scheduled_at" type="datetime-local" class="max-w-64" />
                </UiFormField>
                <div class="flex flex-wrap gap-2">
                    <UiButton :loading="form.processing" :disabled="!count?.reachable" @click="submit('send')">
                        <template #leading><Send class="h-3.5 w-3.5" /></template>
                        Send now
                    </UiButton>
                    <UiButton variant="secondary" :disabled="!form.scheduled_at || !count?.reachable" @click="submit('schedule')">Send at that time</UiButton>
                    <UiButton variant="ghost" @click="submit('draft')">Keep as a draft</UiButton>
                </div>
            </FormSection>
        </div>

        <UiModal :open="previewing" title="Preview" size="lg" @close="previewing = false">
            <iframe :srcdoc="previewHtml" title="The mail as it will look" class="h-[70vh] w-full rounded-lg border bg-white" style="border-color: var(--border-subtle)" sandbox="" />
        </UiModal>
    </AppLayout>
</template>
