<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Star } from 'lucide-vue-next';

import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';

/**
 * The student's own review of a course: write it, see where it stands,
 * rewrite it. Whether it may appear on the public site is asked every time.
 */
const props = defineProps({
    courseId: { type: Number, required: true },
    review: { type: Object, default: null },
});

const editing = ref(props.review === null);
const form = useForm({
    rating: props.review?.rating ?? 0,
    title: props.review?.title ?? '',
    body: props.review?.body ?? '',
    may_publish: props.review?.mayPublish ?? false,
});

const statusLine = computed(() => ({
    pending: 'Waiting to be read. Nobody else sees it until then.',
    approved: props.review?.mayPublish
        ? 'Published, for other students and on the course page.'
        : 'Published for other students. You kept it off the public site.',
    hidden: 'Not shown to anybody else.',
}[props.review?.status] ?? ''));

function save() {
    form.post(`/student/courses/${props.courseId}/review`, {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
}
</script>

<template>
    <UiCard>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold">{{ review ? 'Your review' : 'Review this course' }}</h2>
                <UiBadge v-if="review" :tone="review.status === 'approved' ? 'success' : review.status === 'pending' ? 'warning' : 'neutral'" size="sm" dot>
                    {{ review.status }}
                </UiBadge>
            </div>
        </template>

        <div v-if="review && !editing" class="space-y-2">
            <p class="inline-flex gap-0.5" :aria-label="`${review.rating} out of 5`">
                <Star
                    v-for="n in 5"
                    :key="n"
                    class="h-4 w-4"
                    :style="{ color: n <= review.rating ? 'var(--color-warn-500)' : 'var(--border-strong)' }"
                    :fill="n <= review.rating ? 'currentColor' : 'none'"
                    aria-hidden="true"
                />
            </p>
            <p v-if="review.title" class="font-medium">{{ review.title }}</p>
            <p class="whitespace-pre-line text-sm">{{ review.body }}</p>
            <p class="text-xs" style="color: var(--text-muted)">{{ statusLine }}</p>
            <p v-if="review.reply" class="rounded-lg px-3 py-2 text-sm" style="background: var(--surface-sunken)">
                <span class="block text-xs font-medium" style="color: var(--text-muted)">Reply from Unboundbyte</span>
                {{ review.reply }}
            </p>
            <UiButton variant="ghost" size="xs" @click="editing = true">Edit your review</UiButton>
        </div>

        <form v-else class="space-y-4" @submit.prevent="save">
            <UiFormField label="Rating" required :error="form.errors.rating">
                <div class="flex gap-1" role="radiogroup" aria-label="Rating">
                    <button
                        v-for="n in 5"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="form.rating === n"
                        :aria-label="`${n} out of 5`"
                        class="rounded p-0.5"
                        @click="form.rating = n"
                    >
                        <Star
                            class="h-6 w-6"
                            :style="{ color: n <= form.rating ? 'var(--color-warn-500)' : 'var(--border-strong)' }"
                            :fill="n <= form.rating ? 'currentColor' : 'none'"
                            aria-hidden="true"
                        />
                    </button>
                </div>
            </UiFormField>

            <UiFormField label="Headline" :error="form.errors.title">
                <UiInput v-model="form.title" placeholder="In a few words" />
            </UiFormField>

            <UiFormField label="Your review" required hint="What worked, what did not, and who you would recommend it to." :error="form.errors.body">
                <UiTextarea v-model="form.body" :rows="4" />
            </UiFormField>

            <UiCheckbox
                v-model="form.may_publish"
                label="Unboundbyte may show this on the public course page, with my first name and initial"
            />

            <p v-if="review" class="text-xs" style="color: var(--text-muted)">Saving an edit sends it back to be read again.</p>

            <div class="flex justify-end gap-2">
                <UiButton v-if="review" variant="ghost" size="sm" @click="editing = false">Cancel</UiButton>
                <UiButton type="submit" size="sm" :loading="form.processing" :disabled="form.rating === 0">Send review</UiButton>
            </div>
        </form>
    </UiCard>
</template>
