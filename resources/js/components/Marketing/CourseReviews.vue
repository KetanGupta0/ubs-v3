<script setup>
import { Star } from 'lucide-vue-next';

/**
 * What students said about a course, on its public page.
 *
 * Only reviews that were approved and whose authors agreed to the public site
 * reach here, and the average is of exactly these. With none, it renders
 * nothing at all: an empty "reviews" heading reads as a course nobody liked.
 */
defineProps({
    reviews: { type: Object, required: true },
});
</script>

<template>
    <section v-if="reviews.count" class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8" aria-labelledby="reviews-heading">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <h2 id="reviews-heading" class="text-2xl font-semibold" style="color: var(--text-strong)">What students said</h2>
            <p class="text-sm" style="color: var(--text-muted)">
                {{ reviews.average }} out of 5 from {{ reviews.count }} {{ reviews.count === 1 ? 'review' : 'reviews' }} by students on this course
            </p>
        </div>

        <ul class="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <li
                v-for="review in reviews.items"
                :key="review.id"
                class="rounded-[var(--radius-card)] border p-5"
                style="border-color: var(--border-subtle); background: var(--surface)"
            >
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
                <p v-if="review.title" class="mt-2 font-medium" style="color: var(--text-strong)">{{ review.title }}</p>
                <p class="mt-1.5 whitespace-pre-line text-sm">{{ review.body }}</p>
                <p class="mt-3 text-xs" style="color: var(--text-muted)">{{ review.name }} · {{ review.date }}</p>
                <p v-if="review.reply" class="mt-3 rounded-lg px-3 py-2 text-xs" style="background: var(--surface-sunken)">
                    <span class="font-medium">Unboundbyte:</span> {{ review.reply }}
                </p>
            </li>
        </ul>
    </section>
</template>
