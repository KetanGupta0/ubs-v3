<script setup>
/**
 * One college's own view: their students and nobody else's.
 *
 * The at risk column is the reason this screen exists. A coordinator can
 * answer their department from here, and we can see who has quietly stopped
 * turning up while there is still time to do something about it.
 */
import { ref, computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, Users, UserPlus, FileDown, AlertTriangle, GraduationCap,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiSwitch from '@/components/UI/UiSwitch.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    college: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    batches: { type: Array, default: () => [] },
});

const atRisk = computed(() => props.students.filter((student) => student.atRisk).length);

const month = ref(new Date().toISOString().slice(0, 7));

/* ---------------------------------------------------------- bulk enrol */

const open = ref(false);
const pasted = ref('');

const form = useForm({
    batch_id: props.batches[0]?.value ?? null,
    waive_fee: false,
    students: [],
});

/**
 * One student per line: name, email, mobile, roll number, course.
 *
 * A coordinator has the list in a spreadsheet already, so pasting it beats
 * twenty rows of form fields. Everything after the email is optional.
 */
const parsed = computed(() =>
    pasted.value
        .split('\n')
        .map((line) => line.split(/[,\t]/).map((part) => part.trim()))
        .filter((parts) => parts.length >= 2 && parts[0] && parts[1])
        .map(([name, email, mobile, enrollment_number, course_of_study]) => ({
            name,
            email,
            mobile: mobile || null,
            enrollment_number: enrollment_number || null,
            course_of_study: course_of_study || null,
        })),
);

const skipped = computed(() =>
    pasted.value.split('\n').filter((line) => line.trim()).length - parsed.value.length,
);

function submit() {
    form.students = parsed.value;

    form.post(`/admin/colleges/${props.college.slug}/enrol`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            pasted.value = '';
            form.reset();
        },
    });
}
</script>

<template>
    <Head :title="college.name" />

    <AppLayout
        :title="college.name"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Colleges', href: '/admin/colleges' },
            { label: college.name },
        ]"
    >
        <div class="mx-auto max-w-5xl space-y-5">
            <PageHeader
                :title="college.name"
                :description="[college.university, college.city, college.coordinator].filter(Boolean).join(' · ')"
            >
                <template #actions>
                    <UiButton href="/admin/colleges" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Colleges
                    </UiButton>
                    <UiButton
                        :href="`/admin/colleges/${college.slug}/report?month=${month}-01`"
                        :inertia="false"
                        variant="secondary"
                        size="sm"
                    >
                        <template #leading><FileDown class="h-3.5 w-3.5" /></template>
                        Monthly report
                    </UiButton>
                    <UiButton size="sm" :disabled="!batches.length" @click="open = true">
                        <template #leading><UserPlus class="h-3.5 w-3.5" /></template>
                        Enrol a group
                    </UiButton>
                </template>
            </PageHeader>

            <p
                v-if="college.mouExpired || college.mouExpiring"
                class="flex items-start gap-2 rounded-[var(--radius-control)] p-3 text-xs"
                style="background: var(--surface-sunken); color: var(--text-muted)"
            >
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" style="color: var(--color-warn-500)" />
                <span v-if="college.mouExpired">
                    The memorandum with this college expired on {{ college.mouEndsOn }}.
                </span>
                <span v-else>
                    The memorandum with this college ends on {{ college.mouEndsOn }}.
                </span>
            </p>

            <div class="grid gap-3 sm:grid-cols-3">
                <StatTile label="Students" :value="String(students.length)" />
                <StatTile label="Needing a nudge" :value="String(atRisk)" :tone="atRisk ? 'warning' : 'neutral'" />
                <StatTile label="Batches" :value="String(batches.length)" />
            </div>

            <UiEmptyState
                v-if="!students.length"
                :icon="Users"
                title="No students from this college yet"
                description="Paste the coordinator's list and they are created and put on a batch in one go."
            >
                <template #action>
                    <UiButton :disabled="!batches.length" @click="open = true">Enrol a group</UiButton>
                </template>
            </UiEmptyState>

            <UiCard v-else padding="p-0">
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="student in students"
                        :key="student.id"
                        class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6"
                        :style="student.atRisk ? { background: 'var(--surface-sunken)' } : undefined"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium">{{ student.name }}</span>
                                <UiBadge v-if="student.atRisk" tone="warning" size="sm">
                                    <AlertTriangle class="mr-1 h-3 w-3" />needs a nudge
                                </UiBadge>
                            </span>
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ [student.enrollmentNumber, student.courseOfStudy, student.email]
                                    .filter(Boolean).join(' · ') }}
                            </span>
                            <span v-if="student.course" class="mt-0.5 block text-xs" style="color: var(--text-muted)">
                                <GraduationCap class="mr-1 inline h-3 w-3" />
                                {{ [student.course, student.batch].filter(Boolean).join(' · ') }}
                                <span v-if="student.enrolments > 1"> · +{{ student.enrolments - 1 }} more</span>
                            </span>
                        </span>

                        <span class="shrink-0 text-right text-xs" style="color: var(--text-muted)">
                            <span class="block">
                                {{ student.progress ?? 0 }}% done
                                <span v-if="student.attendance !== null"> · {{ student.attendance }}% present</span>
                            </span>
                            <span v-if="student.overall !== null" class="block">
                                marks {{ student.overall }}%
                            </span>
                            <span v-if="student.lastSeen" class="block">last active {{ student.lastSeen }}</span>
                        </span>
                    </li>
                </ul>
            </UiCard>

            <p class="px-1 text-xs" style="color: var(--text-muted)">
                "Needs a nudge" means short on attendance, past a deadline with nothing handed in, or below the
                pass mark once something has actually been marked — early enough to be worth a phone call.
            </p>
        </div>

        <!-- ------------------------------------------------- bulk enrol -->
        <UiModal
            :open="open"
            size="lg"
            title="Enrol a group"
            description="Paste the list. Accounts are created for anybody new, and anybody already with us is matched by email rather than duplicated."
            @close="open = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <UiFormField label="Batch" required :error="form.errors.batch_id">
                    <UiSelect v-model="form.batch_id">
                        <option v-for="batch in batches" :key="batch.value" :value="batch.value">
                            {{ batch.label }}
                        </option>
                    </UiSelect>
                </UiFormField>

                <UiFormField
                    label="Students"
                    required
                    hint="One per line: name, email, mobile, roll number, course. Everything after the email is optional."
                    :error="form.errors.students"
                >
                    <UiTextarea
                        v-model="pasted"
                        :rows="10"
                        placeholder="Asha Verma, asha@example.edu, 9876543210, 21CS045, B.Tech CSE"
                    />
                </UiFormField>

                <p class="text-xs" style="color: var(--text-muted)">
                    {{ parsed.length }} row{{ parsed.length === 1 ? '' : 's' }} read
                    <span v-if="skipped > 0">
                        · {{ skipped }} skipped for having no name or no email
                    </span>
                </p>

                <UiSwitch
                    v-model="form.waive_fee"
                    label="No fee for these students"
                    description="Right when the college is being invoiced for the group separately."
                />
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton :loading="form.processing" :disabled="!parsed.length" @click="submit">
                    Enrol {{ parsed.length || '' }}
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
