<script setup>
/**
 * Setting up a proctored quiz (§9.1a): how it is watched, when it can be sat,
 * and Safe Exam Browser for a final assessment (X10).
 *
 * A proctored quiz can only be started during one of its sittings, so a
 * proctor is actually there; nobody's attempt runs past the sitting's end.
 */
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { MonitorCheck, Plus, Trash2, Upload } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import { blankForm } from '@/support/forms';

const props = defineProps({
    quiz: { type: Object, required: true },
    slots: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
    canProctor: { type: Boolean, default: false },
});

const base = `/admin/proctoring/quizzes/${props.quiz.id}`;

/* ----------------------------------------------------- how it is watched */

const settings = useForm({
    proctoring: props.quiz.proctoring,
    max_exits: props.quiz.maxExits,
    require_seb: props.quiz.requireSeb,
    seb_config_keys: props.quiz.sebConfigKeys,
    seb_exam_keys: props.quiz.sebExamKeys,
});

function saveSettings() {
    settings.transform((data) => ({ ...data, max_exits: data.max_exits || null })).put(base, { preserveScroll: true });
}

const modes = [
    { value: 'off', label: 'Not proctored' },
    { value: 'lockdown', label: 'Lockdown: full screen, watched live' },
];

/* --------------------------------------------- the Safe Exam Browser file */

const sebFile = useForm({ file: null });

function uploadSebFile() {
    sebFile.post(`${base}/safe-exam-browser`, { preserveScroll: true, forceFormData: true, onSuccess: () => sebFile.reset() });
}

/* -------------------------------------------------------------- sittings */

const blankSlot = { starts_at: '', ends_at: '', batch_id: null, note: '' };
const adding = ref(false);
const slot = useForm(structuredClone(blankSlot));

function addSlot() {
    blankForm(slot, blankSlot);
    adding.value = true;
}

function saveSlot() {
    slot.post(`${base}/slots`, { preserveScroll: true, onSuccess: () => (adding.value = false) });
}

function removeSlot(row) {
    if (window.confirm(`Remove the sitting on ${row.window}?`)) router.delete(`/admin/proctoring/slots/${row.id}`, { preserveScroll: true });
}

const states = {
    open: ['Sitting now', 'success'],
    coming: ['Coming', 'brand'],
    ended: ['Ended', 'neutral'],
};
</script>

<template>
    <Head :title="`Proctoring · ${quiz.title}`" />

    <AppLayout
        :title="`Proctoring · ${quiz.title}`"
        :breadcrumbs="[
            { label: 'Admin', href: '/admin' },
            { label: quiz.course, href: `/admin/courses/${quiz.courseId}/quizzes` },
            { label: quiz.title },
            { label: 'Proctoring' },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <PageHeader :title="quiz.title" description="Proctoring: how it is watched, when it can be sat, and Safe Exam Browser for a final assessment.">
                <template #actions>
                    <UiButton v-if="canProctor && quiz.proctoring !== 'off'" :href="`${base}/console`" size="sm" variant="secondary">
                        <template #leading><MonitorCheck class="h-3.5 w-3.5" /></template>
                        Open the console
                    </UiButton>
                </template>
            </PageHeader>

            <!-- ------------------------------------------- how it is watched -->
            <UiCard>
                <template #header><h2 class="text-base font-semibold">How it is watched</h2></template>

                <form id="proctoring-form" class="space-y-4" @submit.prevent="saveSettings">
                    <UiFormField
                        label="Proctoring"
                        hint="Lockdown: started in full screen during a sitting, on a laptop or computer; copying, pasting and the developer tools' shortcuts are off; leaving covers the questions and is logged; a proctor sees each student live. The camera joins once a video relay is set up."
                        :error="settings.errors.proctoring"
                    >
                        <UiSelect v-model="settings.proctoring" :options="modes" />
                    </UiFormField>

                    <template v-if="settings.proctoring !== 'off'">
                        <UiFormField
                            label="Submit it for them after leaving this many times"
                            hint="Leaving full screen, the tab or the window. Empty: never; every time is still logged for a person to weigh. Students read this before they start."
                            :error="settings.errors.max_exits"
                        >
                            <UiInput v-model.number="settings.max_exits" type="number" min="1" max="20" class="max-w-32" />
                        </UiFormField>

                        <UiSwitch
                            v-model="settings.require_seb"
                            label="Require Safe Exam Browser"
                            description="For a final assessment: Safe Exam Browser locks the computer itself, and an ordinary browser cannot start the quiz."
                        />
                        <p v-if="settings.errors.require_seb" class="text-xs" style="color: var(--text-danger)">{{ settings.errors.require_seb }}</p>

                        <template v-if="settings.require_seb">
                            <UiFormField
                                label="Configuration keys"
                                hint="From Safe Exam Browser's configuration tool, Exam tab: the Config Key of the settings file students open, one to a line. Every request of the attempt is checked against it."
                                :error="settings.errors.seb_config_keys"
                            >
                                <UiTextarea v-model="settings.seb_config_keys" :rows="2" class="font-mono text-xs" />
                            </UiFormField>
                            <UiFormField
                                label="Browser exam keys (optional)"
                                hint="To accept only particular versions of Safe Exam Browser. Most quizzes leave this empty."
                                :error="settings.errors.seb_exam_keys"
                            >
                                <UiTextarea v-model="settings.seb_exam_keys" :rows="2" class="font-mono text-xs" />
                            </UiFormField>
                        </template>
                    </template>

                    <div class="flex justify-end">
                        <UiButton type="submit" :loading="settings.processing">Save</UiButton>
                    </div>
                </form>

                <!-- The file students' computers open the quiz with. -->
                <div v-if="quiz.requireSeb" class="mt-5 border-t pt-4" style="border-color: var(--border-subtle)">
                    <p class="text-sm font-medium">Settings file</p>
                    <p class="mt-1 text-sm" style="color: var(--text-muted)">
                        <template v-if="quiz.sebFile">Saved. Students get a button that opens the quiz in Safe Exam Browser with it.</template>
                        <template v-else>None yet. With one, students get a button that opens the quiz in Safe Exam Browser; without, share the file with them yourself.</template>
                    </p>
                    <form class="mt-3 flex flex-wrap items-center gap-3" @submit.prevent="uploadSebFile">
                        <input type="file" accept=".seb" class="block text-sm" aria-label="Safe Exam Browser settings file" @change="sebFile.file = $event.target.files?.[0] ?? null">
                        <UiButton type="submit" size="sm" variant="secondary" :disabled="!sebFile.file" :loading="sebFile.processing">
                            <template #leading><Upload class="h-3.5 w-3.5" /></template>
                            {{ quiz.sebFile ? 'Replace it' : 'Upload' }}
                        </UiButton>
                    </form>
                    <p v-if="sebFile.errors.file" class="mt-1 text-xs" style="color: var(--text-danger)">{{ sebFile.errors.file }}</p>
                </div>
            </UiCard>

            <!-- ------------------------------------------------------ sittings -->
            <UiCard>
                <template #header>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold">Sittings</h2>
                        <UiButton size="sm" @click="addSlot">
                            <template #leading><Plus class="h-3.5 w-3.5" /></template>
                            Add a sitting
                        </UiButton>
                    </div>
                </template>

                <p class="text-sm" style="color: var(--text-muted)">
                    A proctored quiz can be started only during a sitting for the student's batch, so somebody is watching. Nobody's attempt runs past the end of the sitting it started in<template v-if="quiz.timeLimit">, and each has its {{ quiz.timeLimit }} minutes within it</template>.
                </p>

                <ul v-if="slots.length" class="mt-4 divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="row in slots" :key="row.id" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {{ row.window }}
                                <UiBadge :tone="states[row.state][1]" size="sm" dot>{{ states[row.state][0] }}</UiBadge>
                            </p>
                            <p class="text-xs" style="color: var(--text-muted)">
                                {{ row.batch }}<template v-if="row.note"> · {{ row.note }}</template><template v-if="row.started"> · {{ row.started }} started</template>
                            </p>
                        </div>
                        <UiButton v-if="!row.started" size="xs" variant="ghost" icon :aria-label="`Remove the sitting on ${row.window}`" @click="removeSlot(row)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--text-danger)" />
                        </UiButton>
                    </li>
                </ul>
                <p v-else class="mt-4 text-sm font-medium">No sittings yet, so nobody can start it.</p>
            </UiCard>
        </div>

        <UiModal :open="adding" title="Add a sitting" @close="adding = false">
            <form id="slot-form" class="space-y-4" @submit.prevent="saveSlot">
                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Starts" required :error="slot.errors.starts_at">
                        <UiInput v-model="slot.starts_at" type="datetime-local" />
                    </UiFormField>
                    <UiFormField label="Ends" required hint="No attempt runs past this." :error="slot.errors.ends_at">
                        <UiInput v-model="slot.ends_at" type="datetime-local" />
                    </UiFormField>
                </div>
                <UiFormField v-if="batches.length" label="For" :error="slot.errors.batch_id">
                    <UiSelect v-model="slot.batch_id">
                        <option :value="null">Everybody it is set for</option>
                        <option v-for="batch in batches" :key="batch.value" :value="batch.value">{{ batch.label }}</option>
                    </UiSelect>
                </UiFormField>
                <UiFormField label="Note" hint="Shown to students, for example who is proctoring or a room." :error="slot.errors.note">
                    <UiInput v-model="slot.note" maxlength="160" />
                </UiFormField>
            </form>
            <template #footer>
                <UiButton variant="ghost" @click="adding = false">Cancel</UiButton>
                <UiButton type="submit" form="slot-form" :loading="slot.processing">Add</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
