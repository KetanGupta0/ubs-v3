<script setup>
/**
 * Weekly mentor reviews.
 *
 * An internship's evaluation form is built from these rather than written from
 * memory at the end, which is the difference between an assessment and a
 * recollection.
 */
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, NotebookPen } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    batch: { type: Object, required: true },
    students: { type: Array, default: () => [] },
    criteria: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
});

const open = ref(false);

const form = useForm({
    user_id: null,
    week_number: props.batch.week ?? 1,
    reviewed_on: new Date().toISOString().slice(0, 10),
    summary: '',
    what_went_well: '',
    to_improve: '',
    marks: Object.fromEntries(props.criteria.map((criterion) => [criterion.key, null])),
});

const labelFor = computed(() =>
    Object.fromEntries(props.criteria.map((criterion) => [criterion.key, criterion.label])),
);

function openForm(review = null) {
    form.clearErrors();
    form.user_id = review?.userId ?? props.students[0]?.value ?? null;
    form.week_number = review?.week ?? props.batch.week ?? 1;
    form.reviewed_on = new Date().toISOString().slice(0, 10);
    form.summary = review?.summary ?? '';
    form.what_went_well = review?.wentWell ?? '';
    form.to_improve = review?.toImprove ?? '';
    form.marks = Object.fromEntries(
        props.criteria.map((criterion) => [criterion.key, review?.marks?.[criterion.key] ?? null]),
    );
    open.value = true;
}

function save() {
    form.post(`/admin/batches/${props.batch.id}/reviews`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function remove(review) {
    if (confirm(`Remove week ${review.week} for ${review.student}?`)) {
        router.delete(`/admin/batches/${props.batch.id}/reviews/${review.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`Mentor reviews — ${batch.name}`" />

    <AppLayout
        title="Mentor reviews"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: batch.name, href: `/admin/batches/${batch.id}/run` },
            { label: 'Reviews' },
        ]"
    >
        <div class="mx-auto max-w-3xl space-y-4">
            <PageHeader
                title="Weekly reviews"
                :description="`${batch.course} · ${batch.name}. Saving the same student and week again replaces that week's review.`"
            >
                <template #actions>
                    <UiButton :href="`/admin/batches/${batch.id}/run`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        Batch
                    </UiButton>
                    <UiButton size="sm" :disabled="!students.length" @click="openForm()">
                        <template #leading><Plus class="h-3.5 w-3.5" /></template>
                        Review
                    </UiButton>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="!reviews.length"
                :icon="NotebookPen"
                title="No reviews yet"
                description="One a week per intern is enough, and it is what the evaluation form is built from."
            />

            <UiCard v-for="review in reviews" :key="review.id" padding="p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                            {{ review.student }}
                            <UiBadge size="sm">week {{ review.week }}</UiBadge>
                            <UiBadge v-if="review.average" tone="brand" size="sm">{{ review.average }} / 10</UiBadge>
                        </p>

                        <p class="mt-2 whitespace-pre-line text-sm" style="color: var(--text-muted)">
                            {{ review.summary }}
                        </p>

                        <div v-if="review.wentWell || review.toImprove" class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div v-if="review.wentWell">
                                <p class="text-xs font-medium">Went well</p>
                                <p class="mt-0.5 whitespace-pre-line text-xs" style="color: var(--text-muted)">
                                    {{ review.wentWell }}
                                </p>
                            </div>
                            <div v-if="review.toImprove">
                                <p class="text-xs font-medium">To work on</p>
                                <p class="mt-0.5 whitespace-pre-line text-xs" style="color: var(--text-muted)">
                                    {{ review.toImprove }}
                                </p>
                            </div>
                        </div>

                        <ul v-if="Object.keys(review.marks).length" class="mt-3 flex flex-wrap gap-1.5">
                            <li v-for="(mark, key) in review.marks" :key="key">
                                <UiBadge size="sm">{{ labelFor[key] ?? key }}: {{ mark }}</UiBadge>
                            </li>
                        </ul>

                        <p class="mt-3 text-xs" style="color: var(--text-muted)">
                            {{ [review.reviewer, review.reviewedOn].filter(Boolean).join(' · ') }}
                        </p>
                    </div>

                    <UiButton variant="ghost" size="xs" @click="remove(review)">
                        <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                    </UiButton>
                </div>
            </UiCard>
        </div>

        <UiModal :open="open" size="lg" title="Weekly review" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="Student" required :error="form.errors.user_id">
                        <UiSelect v-model="form.user_id">
                            <option v-for="student in students" :key="student.value" :value="student.value">
                                {{ student.label }}
                            </option>
                        </UiSelect>
                    </UiFormField>

                    <UiFormField label="Week" required :error="form.errors.week_number">
                        <UiInput v-model.number="form.week_number" type="number" min="1" max="104" />
                    </UiFormField>

                    <UiFormField label="Reviewed on" required :error="form.errors.reviewed_on">
                        <UiDateInput v-model="form.reviewed_on" />
                    </UiFormField>
                </div>

                <UiFormField label="Summary" required :error="form.errors.summary">
                    <UiTextarea v-model="form.summary" :rows="4" />
                </UiFormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <UiFormField label="Went well" :error="form.errors.what_went_well">
                        <UiTextarea v-model="form.what_went_well" :rows="3" />
                    </UiFormField>

                    <UiFormField label="To work on" :error="form.errors.to_improve">
                        <UiTextarea v-model="form.to_improve" :rows="3" />
                    </UiFormField>
                </div>

                <div>
                    <p class="mb-2 text-xs font-medium" style="color: var(--text-muted)">
                        Marks out of ten — leave blank where it does not apply yet
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <UiFormField
                            v-for="criterion in criteria"
                            :key="criterion.key"
                            :label="criterion.label"
                            :error="form.errors[`marks.${criterion.key}`]"
                        >
                            <UiInput v-model.number="form.marks[criterion.key]" type="number" min="0" max="10" />
                        </UiFormField>
                    </div>
                </div>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="open = false">Cancel</UiButton>
                <UiButton :loading="form.processing" @click="save">Save</UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
