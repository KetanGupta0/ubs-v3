<script setup>
/**
 * Running one batch: who is on it, what is scheduled, what has been announced.
 *
 * Marking a register happens on its own screen, because it is the one thing
 * here done under time pressure with a class waiting.
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import {
    Plus, Pencil, Trash2, Video, Users, Megaphone, ShieldAlert,
    Award, UserPlus, Pin, ExternalLink,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTabs from '@/components/UI/UiTabs.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiCombobox from '@/components/UI/UiCombobox.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    sessions: { type: Array, default: () => [] },
    announcements: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    trainers: { type: Array, default: () => [] },
});

const base = computed(() => `/admin/batches/${props.batch.id}`);

const tab = ref('students');

const tabs = computed(() => [
    { value: 'students', label: 'Students', count: props.students.length },
    { value: 'schedule', label: 'Schedule', count: props.sessions.length },
    { value: 'announcements', label: 'Announcements', count: props.announcements.length },
]);

const unmarked = computed(() => props.sessions.filter((session) => session.past && !session.marked).length);

/* -------------------------------------------------------------- classes */

const sessionOpen = ref(false);
const editingSession = ref(null);

const sessionForm = useForm({
    title: '',
    agenda: '',
    scheduled_at: '',
    duration_minutes: 90,
    meet_link: props.batch.meetLink ?? '',
    recording_url: '',
    status: 'scheduled',
});

function openSession(session = null) {
    editingSession.value = session;
    sessionForm.clearErrors();
    sessionForm.title = session?.title ?? '';
    sessionForm.agenda = session?.agenda ?? '';
    sessionForm.scheduled_at = session?.atValue ?? '';
    sessionForm.duration_minutes = session?.duration ?? 90;
    sessionForm.meet_link = session?.meetLink ?? props.batch.meetLink ?? '';
    sessionForm.recording_url = session?.recordingUrl ?? '';
    sessionForm.status = session?.status ?? 'scheduled';
    sessionOpen.value = true;
}

function saveSession() {
    const done = {
        preserveScroll: true,
        onSuccess: () => {
            sessionOpen.value = false;
            editingSession.value = null;
            sessionForm.reset();
        },
    };

    editingSession.value
        ? sessionForm.put(`${base.value}/sessions/${editingSession.value.id}`, done)
        : sessionForm.post(`${base.value}/sessions`, done);
}

function removeSession(session) {
    if (confirm(`Remove "${session.title}"?`)) {
        router.delete(`${base.value}/sessions/${session.id}`, { preserveScroll: true });
    }
}

/* ------------------------------------------------------------ enrolling */

const enrolOpen = ref(false);

const enrolForm = useForm({
    user_ids: [],
    waive_fee: false,
    notify: true,
});

const picked = ref(null);

function addPicked(id) {
    if (id && !enrolForm.user_ids.includes(id)) enrolForm.user_ids.push(id);
    picked.value = null;
}

function nameFor(id) {
    return props.candidates.find((candidate) => candidate.value === id)?.label ?? `#${id}`;
}

function saveEnrolment() {
    enrolForm.post(`${base.value}/enrol`, {
        preserveScroll: true,
        onSuccess: () => {
            enrolOpen.value = false;
            enrolForm.reset();
        },
    });
}

function setEnrolment(student, payload) {
    router.put(`${base.value}/enrolments/${student.enrolmentId}`, payload, { preserveScroll: true });
}

function drop(student) {
    if (confirm(`Remove ${student.name} from this batch?`)) {
        setEnrolment(student, { status: 'dropped' });
    }
}

/* -------------------------------------------------------- announcements */

const announceOpen = ref(false);

const announceForm = useForm({
    title: '',
    body: '',
    is_pinned: false,
    course_wide: false,
});

function saveAnnouncement() {
    announceForm.post(`${base.value}/announcements`, {
        preserveScroll: true,
        onSuccess: () => {
            announceOpen.value = false;
            announceForm.reset();
        },
    });
}

function removeAnnouncement(announcement) {
    if (confirm('Remove this announcement?')) {
        router.delete(`${base.value}/announcements/${announcement.id}`, { preserveScroll: true });
    }
}

/* ------------------------------------------------------------- warnings */

const warnOpen = ref(false);

const warnForm = useForm({
    user_id: null,
    reason: '',
    level: null,
    live_session_id: null,
    private_note: '',
});

function openWarn(student) {
    warnForm.clearErrors();
    warnForm.reset();
    warnForm.user_id = student.userId;
    warnOpen.value = true;
}

function saveWarning() {
    warnForm.post(`${base.value}/warn`, {
        preserveScroll: true,
        onSuccess: () => {
            warnOpen.value = false;
            warnForm.reset();
        },
    });
}

const attendanceTone = (student) => {
    if (student.attendance === null) return 'brand';
    if (props.batch.minimumAttendance && student.attendance < props.batch.minimumAttendance) return 'danger';
    return student.attendance >= 75 ? 'success' : 'warning';
};
</script>

<template>
    <Head :title="batch.name" />

    <AppLayout
        :title="batch.name"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Batches', href: '/admin/batches' },
            { label: batch.name },
        ]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                :title="batch.name"
                :description="[batch.course, batch.code, batch.college].filter(Boolean).join(' · ')"
            >
                <template #actions>
                    <UiButton :href="`${base}/warnings`" variant="ghost" size="sm">
                        <template #leading><ShieldAlert class="h-3.5 w-3.5" /></template>
                        Notices
                    </UiButton>
                    <UiButton :href="`${base}/reviews`" variant="ghost" size="sm">Mentor reviews</UiButton>
                    <UiButton :href="`${base}/credentials`" variant="secondary" size="sm">
                        <template #leading><Award class="h-3.5 w-3.5" /></template>
                        Credentials
                    </UiButton>
                </template>
            </PageHeader>

            <div class="grid gap-3 sm:grid-cols-4">
                <StatTile label="Students" :value="String(students.length)" />
                <StatTile
                    label="Classes held"
                    :value="`${sessions.filter((session) => session.past).length} of ${sessions.length}`"
                />
                <StatTile label="Registers not marked" :value="String(unmarked)" :tone="unmarked ? 'warning' : 'neutral'" />
                <StatTile label="Week" :value="batch.week ? `Week ${batch.week}` : 'Not started'" />
            </div>

            <UiTabs v-model="tab" :tabs="tabs" />

            <!-- ------------------------------------------------ students -->
            <section v-if="tab === 'students'" class="space-y-3">
                <div class="flex flex-wrap justify-end gap-2">
                    <UiButton size="sm" @click="enrolOpen = true">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Add students
                    </UiButton>
                </div>

                <UiEmptyState
                    v-if="!students.length"
                    :icon="Users"
                    title="Nobody on this batch yet"
                    description="Add students here, or enrol a whole college group from the college desk."
                />

                <UiCard v-else padding="p-0">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="student in students"
                            :key="student.enrolmentId"
                            class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ student.name }}</span>
                                    <UiBadge v-if="student.status !== 'active'" size="sm">{{ student.status }}</UiBadge>
                                    <UiBadge v-if="!student.hasPaid" tone="warning" size="sm">fee due</UiBadge>
                                    <UiBadge v-if="student.warnings" tone="danger" size="sm">
                                        {{ student.warnings }} open notice{{ student.warnings === 1 ? '' : 's' }}
                                    </UiBadge>
                                </span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ student.email }}</span>

                                <span class="mt-2 block max-w-xs">
                                    <UiProgress :value="student.progress ?? 0" label="Course" size="sm" />
                                </span>
                            </span>

                            <span class="shrink-0 text-right">
                                <UiBadge :tone="attendanceTone(student)" size="sm">
                                    {{ student.attendance === null ? 'no classes yet' : `${student.attendance}% present` }}
                                </UiBadge>
                                <span class="mt-1 block text-xs" style="color: var(--text-muted)">
                                    {{ student.attended }} of {{ student.held }}
                                </span>
                            </span>

                            <span class="flex shrink-0 items-center gap-1">
                                <UiButton
                                    v-if="!student.hasPaid"
                                    variant="ghost"
                                    size="xs"
                                    @click="setEnrolment(student, { has_paid: true })"
                                >
                                    Mark paid
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="openWarn(student)">
                                    <ShieldAlert class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="drop(student)">
                                    <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                                </UiButton>
                            </span>
                        </li>
                    </ul>
                </UiCard>
            </section>

            <!-- ------------------------------------------------ schedule -->
            <section v-else-if="tab === 'schedule'" class="space-y-3">
                <div class="flex flex-wrap justify-end gap-2">
                    <UiButton size="sm" @click="openSession()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Schedule a class
                    </UiButton>
                </div>

                <UiEmptyState
                    v-if="!sessions.length"
                    :icon="Video"
                    title="Nothing scheduled"
                    description="Classes run on Google Meet. Put the link on the batch and it fills in here by default."
                />

                <UiCard v-else padding="p-0">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li
                            v-for="session in sessions"
                            :key="session.id"
                            class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium">{{ session.title }}</span>
                                    <UiBadge v-if="session.live" tone="danger" size="sm" dot>live now</UiBadge>
                                    <UiBadge v-if="session.status === 'cancelled'" size="sm">cancelled</UiBadge>
                                    <UiBadge v-if="session.past && !session.marked" tone="warning" size="sm">
                                        register not marked
                                    </UiBadge>
                                </span>
                                <span class="block text-xs" style="color: var(--text-muted)">
                                    {{ session.at }} · {{ session.duration }} min
                                    <span v-if="session.marked"> · {{ session.present }} present</span>
                                </span>
                            </span>

                            <span class="flex shrink-0 items-center gap-1">
                                <UiButton
                                    v-if="session.meetLink"
                                    :href="session.meetLink"
                                    :inertia="false"
                                    target="_blank"
                                    rel="noopener"
                                    variant="ghost"
                                    size="xs"
                                >
                                    <ExternalLink class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton
                                    :href="`${base}/sessions/${session.id}/register`"
                                    :variant="session.past && !session.marked ? 'primary' : 'secondary'"
                                    size="xs"
                                >
                                    Register
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="openSession(session)">
                                    <Pencil class="h-3.5 w-3.5" />
                                </UiButton>
                                <UiButton variant="ghost" size="xs" @click="removeSession(session)">
                                    <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                                </UiButton>
                            </span>
                        </li>
                    </ul>
                </UiCard>
            </section>

            <!-- ------------------------------------------- announcements -->
            <section v-else class="space-y-3">
                <div class="flex flex-wrap justify-end gap-2">
                    <UiButton size="sm" @click="announceOpen = true">
                        <template #leading><Megaphone class="h-3.5 w-3.5" /></template>
                        Post an announcement
                    </UiButton>
                </div>

                <UiEmptyState
                    v-if="!announcements.length"
                    :icon="Megaphone"
                    title="Nothing posted"
                    description="Use this for class changes and deadlines — it reaches everybody on the batch."
                />

                <UiCard v-for="announcement in announcements" :key="announcement.id" padding="p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 text-sm font-semibold">
                                <Pin v-if="announcement.pinned" class="h-3.5 w-3.5" style="color: var(--color-brand-500)" />
                                {{ announcement.title }}
                            </p>
                            <p class="mt-1 whitespace-pre-line text-sm" style="color: var(--text-muted)">
                                {{ announcement.body }}
                            </p>
                            <p class="mt-2 text-xs" style="color: var(--text-muted)">
                                {{ announcement.author }} · {{ announcement.publishedAt }}
                            </p>
                        </div>

                        <UiButton variant="ghost" size="xs" @click="removeAnnouncement(announcement)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </div>
                </UiCard>
            </section>
        </div>

        <!-- --------------------------------------------------- class form -->
        <UiModal
            :open="sessionOpen"
            size="lg"
            :title="editingSession ? 'Edit class' : 'Schedule a class'"
            @close="sessionOpen = false"
        >
            <form class="space-y-4" @submit.prevent="saveSession">
                <UiFormField label="Title" required :error="sessionForm.errors.title">
                    <UiInput v-model="sessionForm.title" placeholder="Session 4 — forms and validation" />
                </UiFormField>

                <UiFormField label="Agenda" :error="sessionForm.errors.agenda">
                    <UiTextarea v-model="sessionForm.agenda" :rows="3" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="When" required :error="sessionForm.errors.scheduled_at">
                        <UiInput v-model="sessionForm.scheduled_at" type="datetime-local" />
                    </UiFormField>

                    <UiFormField label="Minutes" required :error="sessionForm.errors.duration_minutes">
                        <UiInput v-model.number="sessionForm.duration_minutes" type="number" min="15" max="600" />
                    </UiFormField>
                </div>

                <UiFormField
                    label="Meet link"
                    hint="The join button opens fifteen minutes before the start and closes thirty minutes after it ends."
                    :error="sessionForm.errors.meet_link"
                >
                    <UiInput v-model="sessionForm.meet_link" placeholder="https://meet.google.com/…" />
                </UiFormField>

                <UiFormField label="Recording link" :error="sessionForm.errors.recording_url">
                    <UiInput v-model="sessionForm.recording_url" placeholder="https://" />
                </UiFormField>

                <UiFormField v-if="editingSession" label="Status" :error="sessionForm.errors.status">
                    <UiSelect v-model="sessionForm.status">
                        <option value="scheduled">Scheduled</option>
                        <option value="held">Held</option>
                        <option value="cancelled">Cancelled</option>
                    </UiSelect>
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="sessionOpen = false">Cancel</UiButton>
                <UiButton :loading="sessionForm.processing" @click="saveSession">Save</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------- add students -->
        <UiModal :open="enrolOpen" title="Add students to this batch" @close="enrolOpen = false">
            <form class="space-y-4" @submit.prevent="saveEnrolment">
                <UiFormField label="Student" :error="enrolForm.errors.user_ids">
                    <UiCombobox
                        :model-value="picked"
                        :options="candidates"
                        placeholder="Search by name…"
                        @update:model-value="addPicked"
                    />
                </UiFormField>

                <ul v-if="enrolForm.user_ids.length" class="flex flex-wrap gap-1.5">
                    <li v-for="id in enrolForm.user_ids" :key="id">
                        <UiBadge size="sm">{{ nameFor(id) }}</UiBadge>
                    </li>
                </ul>

                <UiSwitch
                    v-model="enrolForm.waive_fee"
                    label="No fee for these students"
                    description="Use for a college tie-up that has been invoiced separately."
                />
                <UiSwitch v-model="enrolForm.notify" label="Tell them by email and SMS" />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="enrolOpen = false">Cancel</UiButton>
                <UiButton
                    :loading="enrolForm.processing"
                    :disabled="!enrolForm.user_ids.length"
                    @click="saveEnrolment"
                >
                    Add {{ enrolForm.user_ids.length || '' }}
                </UiButton>
            </template>
        </UiModal>

        <!-- -------------------------------------------------- announcement -->
        <UiModal :open="announceOpen" title="Post an announcement" @close="announceOpen = false">
            <form class="space-y-4" @submit.prevent="saveAnnouncement">
                <UiFormField label="Title" required :error="announceForm.errors.title">
                    <UiInput v-model="announceForm.title" />
                </UiFormField>

                <UiFormField label="Message" required :error="announceForm.errors.body">
                    <UiTextarea v-model="announceForm.body" :rows="6" />
                </UiFormField>

                <UiSwitch v-model="announceForm.is_pinned" label="Pin it to the top" />
                <UiSwitch
                    v-model="announceForm.course_wide"
                    label="Send to every batch of this course"
                    description="Off means this batch only."
                />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="announceOpen = false">Cancel</UiButton>
                <UiButton :loading="announceForm.processing" @click="saveAnnouncement">Post</UiButton>
            </template>
        </UiModal>

        <!-- ------------------------------------------------------ warning -->
        <UiModal :open="warnOpen" title="Send a notice" @close="warnOpen = false">
            <form class="space-y-4" @submit.prevent="saveWarning">
                <p class="text-sm" style="color: var(--text-muted)">
                    The level steps up on its own if this is not their first. Leave it blank unless you want to
                    override that.
                </p>

                <UiFormField
                    label="What they will read"
                    required
                    hint="Specific and early beats formal and late."
                    :error="warnForm.errors.reason"
                >
                    <UiTextarea v-model="warnForm.reason" :rows="4" />
                </UiFormField>

                <UiFormField label="Level" :error="warnForm.errors.level">
                    <UiSelect v-model="warnForm.level">
                        <option :value="null">Decide for me</option>
                        <option value="notice">Notice</option>
                        <option value="warning">Warning</option>
                        <option value="escalation">Escalation — contacts the guardian</option>
                    </UiSelect>
                </UiFormField>

                <UiFormField
                    label="Private note"
                    hint="For us. The student never sees this."
                    :error="warnForm.errors.private_note"
                >
                    <UiTextarea v-model="warnForm.private_note" :rows="3" />
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="warnOpen = false">Cancel</UiButton>
                <UiButton :loading="warnForm.processing" @click="saveWarning">Send</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
