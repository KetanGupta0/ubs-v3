<script setup>
/**
 * The enquiry form, used on every page that can produce a lead.
 *
 * It carries what the visitor was looking at, so the reply can start from their
 * context instead of asking them to explain it again.
 */
import { ref, computed, watch } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Send, CheckCircle2, ShieldCheck } from 'lucide-vue-next';

import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiFormField from '@/components/UI/UiFormField.vue';

const props = defineProps({
    /** 'solution', 'service', 'training' or 'general'. */
    interest: { type: String, default: 'general' },
    solutionSlug: { type: String, default: null },
    serviceSlug: { type: String, default: null },
    courseSlug: { type: String, default: null },
    /** Shown above the form so the visitor can see what it is attached to. */
    contextLabel: { type: String, default: null },
    /** Budget and timeline only make sense on a build enquiry. */
    showProjectFields: { type: Boolean, default: false },
    /** College name and headcount, for a tie-up enquiry. */
    showCollegeFields: { type: Boolean, default: false },
    compact: { type: Boolean, default: false },
});

const page = usePage();
const reference = ref(null);

const form = useForm({
    name: '',
    email: '',
    mobile: '',
    company: '',
    message: '',
    interest: props.interest,
    solution_slug: props.solutionSlug,
    service_slug: props.serviceSlug,
    course_slug: props.courseSlug,
    budget_band: '',
    timeline: '',
    college_name: '',
    student_count: '',
    source_page: typeof window !== 'undefined' ? window.location.pathname : null,
    // Never shown to a person. A crude bot fills every field it finds.
    website: '',
});

watch(
    () => page.props.flash?.leadReference,
    (value) => {
        if (value) reference.value = value;
    },
);

const budgets = [
    { value: 'Under ₹3L', label: 'Under ₹3 lakh' },
    { value: '₹3L – ₹10L', label: '₹3 lakh to ₹10 lakh' },
    { value: '₹10L – ₹25L', label: '₹10 lakh to ₹25 lakh' },
    { value: 'Above ₹25L', label: 'Above ₹25 lakh' },
    { value: 'Not sure yet', label: 'Not sure yet' },
];

const timelines = [
    { value: 'As soon as possible', label: 'As soon as possible' },
    { value: 'Within 3 months', label: 'Within 3 months' },
    { value: 'This year', label: 'This year' },
    { value: 'Just exploring', label: 'Just exploring' },
];

const placeholder = computed(() => {
    switch (props.interest) {
        case 'solution':
            return 'What does your current process look like, and where does it hurt most?';
        case 'training':
            return 'What are you hoping to be able to do by the end, and what have you tried already?';
        case 'internship':
            return 'Which year are you in, what does your college require, and what have you built so far?';
        case 'college':
            return 'Which course and year are the students in, how many, and what does your curriculum require?';
        case 'service':
            return 'What do you have running today, and what needs to change about it?';
        default:
            return 'Tell us what you are trying to build or learn.';
    }
});

function submit() {
    form.post('/enquiries', {
        preserveScroll: true,
        onSuccess: () => form.reset(
            'name', 'email', 'mobile', 'company', 'message',
            'budget_band', 'timeline', 'college_name', 'student_count',
        ),
    });
}
</script>

<template>
    <!-- Confirmation replaces the form, so nobody sends the same thing twice. -->
    <div
        v-if="reference"
        class="rounded-[var(--radius-card)] border p-6 text-center sm:p-8"
        style="border-color: var(--border-subtle); background: var(--surface)"
    >
        <span class="inline-flex h-11 w-11 items-center justify-center rounded-full bg-green-50 dark:bg-green-950/60">
            <CheckCircle2 class="h-5 w-5 text-signal-500" aria-hidden="true" />
        </span>

        <h3 class="mt-4 text-lg font-semibold">We have your enquiry</h3>
        <p class="mt-2 text-sm" style="color: var(--text-muted)">
            A confirmation is on its way to your email. Someone will read this properly and reply
            within one working day.
        </p>
        <p class="mt-4 text-sm">
            <span style="color: var(--text-muted)">Your reference is</span>
            <span class="ml-1.5 font-mono font-semibold" style="color: var(--text-strong)">{{ reference }}</span>
        </p>

        <button
            type="button"
            class="mt-5 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
            @click="reference = null"
        >
            Send another enquiry
        </button>
    </div>

    <form
        v-else
        class="rounded-[var(--radius-card)] border p-5 sm:p-6"
        style="border-color: var(--border-subtle); background: var(--surface)"
        @submit.prevent="submit"
    >
        <p
            v-if="contextLabel"
            class="mb-5 rounded-xl px-3.5 py-2.5 text-sm"
            style="background: var(--surface-sunken); color: var(--text-base)"
        >
            Asking about <span class="font-semibold" style="color: var(--text-strong)">{{ contextLabel }}</span>
        </p>

        <div class="space-y-4">
            <div :class="compact ? 'space-y-4' : 'grid gap-4 sm:grid-cols-2'">
                <UiFormField label="Your name" required :error="form.errors.name">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.name" :invalid="field.invalid" autocomplete="name" required />
                    </template>
                </UiFormField>

                <UiFormField label="Email" required :error="form.errors.email">
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.email"
                            type="email"
                            :invalid="field.invalid"
                            autocomplete="email"
                            autocapitalize="none"
                            required
                        />
                    </template>
                </UiFormField>
            </div>

            <div :class="compact ? 'space-y-4' : 'grid gap-4 sm:grid-cols-2'">
                <UiFormField label="Mobile" hint="Optional, but it speeds things up." :error="form.errors.mobile">
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.mobile"
                            type="tel"
                            :invalid="field.invalid"
                            autocomplete="tel"
                            placeholder="98765 43210"
                        />
                    </template>
                </UiFormField>

                <UiFormField v-if="!showCollegeFields" label="Company" :error="form.errors.company">
                    <template #default="field">
                        <UiInput :id="field.id" v-model="form.company" :invalid="field.invalid" autocomplete="organization" />
                    </template>
                </UiFormField>

                <UiFormField
                    v-else
                    label="Your role"
                    hint="For example training and placement officer."
                    :error="form.errors.company"
                >
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.company"
                            :invalid="field.invalid"
                            placeholder="Training and placement officer"
                        />
                    </template>
                </UiFormField>
            </div>

            <div v-if="showProjectFields" :class="compact ? 'space-y-4' : 'grid gap-4 sm:grid-cols-2'">
                <UiFormField label="Indicative budget" :error="form.errors.budget_band">
                    <template #default="field">
                        <UiSelect
                            :id="field.id"
                            v-model="form.budget_band"
                            :options="budgets"
                            placeholder="Prefer not to say"
                        />
                    </template>
                </UiFormField>

                <UiFormField label="Timeline" :error="form.errors.timeline">
                    <template #default="field">
                        <UiSelect
                            :id="field.id"
                            v-model="form.timeline"
                            :options="timelines"
                            placeholder="Not decided"
                        />
                    </template>
                </UiFormField>
            </div>

            <div v-if="showCollegeFields" :class="compact ? 'space-y-4' : 'grid gap-4 sm:grid-cols-2'">
                <UiFormField label="College or institute" required :error="form.errors.college_name">
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.college_name"
                            :invalid="field.invalid"
                            placeholder="Name of your institution"
                        />
                    </template>
                </UiFormField>

                <UiFormField
                    label="Roughly how many students?"
                    hint="An estimate is fine."
                    :error="form.errors.student_count"
                >
                    <template #default="field">
                        <UiInput
                            :id="field.id"
                            v-model="form.student_count"
                            type="number"
                            min="1"
                            :invalid="field.invalid"
                            placeholder="40"
                        />
                    </template>
                </UiFormField>
            </div>

            <UiFormField label="What would you like to tell us?" required :error="form.errors.message">
                <template #default="field">
                    <UiTextarea
                        :id="field.id"
                        v-model="form.message"
                        :invalid="field.invalid"
                        :rows="compact ? 4 : 5"
                        :placeholder="placeholder"
                        required
                    />
                </template>
            </UiFormField>

            <!-- Hidden from people and from screen readers, visible to bots. -->
            <div class="hidden" aria-hidden="true">
                <label for="website-field">Leave this field empty</label>
                <input id="website-field" v-model="form.website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="flex items-center gap-1.5 text-xs" style="color: var(--text-muted)">
                    <ShieldCheck class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    We use this only to reply. No lists, no sharing.
                </p>

                <UiButton type="submit" size="lg" :loading="form.processing" :block="compact">
                    Send enquiry
                    <template #trailing><Send class="h-4 w-4" /></template>
                </UiButton>
            </div>
        </div>
    </form>
</template>
