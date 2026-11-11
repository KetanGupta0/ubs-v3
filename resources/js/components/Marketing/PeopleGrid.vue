<script setup>
/**
 * The people behind the work: the team on the About page, the mentors on the
 * Training page.
 *
 * Real people only, added in the admin panel (/admin/team). With nobody to
 * show it renders nothing at all, heading included, rather than an empty
 * frame or a placeholder face.
 */
import { Linkedin } from 'lucide-vue-next';

import SectionHeading from '@/components/Marketing/SectionHeading.vue';

defineProps({
    people: { type: Array, default: () => [] },
    eyebrow: { type: String, required: true },
    title: { type: String, required: true },
    body: { type: String, default: null },
    /** Mentors lead with what they teach; the team with who they are. */
    variant: { type: String, default: 'team' },
    /** Match the page's own column, so the section's edge lines up with the rest. */
    width: { type: String, default: 'max-w-5xl' },
});

const initials = (name) => name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
</script>

<template>
    <section v-if="people.length" :class="['mx-auto px-4 py-14 sm:px-6 lg:px-8', width]">
        <SectionHeading :eyebrow="eyebrow" :title="title" :body="body" />

        <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <li
                v-for="person in people"
                :key="person.id"
                class="flex flex-col rounded-[var(--radius-card)] border bg-[var(--surface)] p-5"
                style="border-color: var(--border-subtle)"
            >
                <div class="flex items-center gap-4">
                    <img
                        v-if="person.photo"
                        :src="person.photo"
                        :alt="`${person.name}`"
                        loading="lazy"
                        class="h-16 w-16 shrink-0 rounded-full border object-cover"
                        style="border-color: var(--border-subtle)"
                    >
                    <span
                        v-else
                        class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-brand-50 text-lg font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-300"
                        aria-hidden="true"
                    >
                        {{ initials(person.name) }}
                    </span>

                    <div class="min-w-0">
                        <h3 class="text-base font-semibold" style="color: var(--text-strong)">{{ person.name }}</h3>
                        <p class="text-sm" style="color: var(--text-muted)">{{ person.role }}</p>
                        <p v-if="person.experience" class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ person.experience }}</p>
                    </div>
                </div>

                <p v-if="person.bio" class="mt-4 text-sm leading-relaxed" style="color: var(--text-base)">{{ person.bio }}</p>

                <ul v-if="variant === 'mentor' && person.skills.length" class="mt-4 flex flex-wrap gap-1.5" :aria-label="`What ${person.name} teaches`">
                    <li
                        v-for="skill in person.skills"
                        :key="skill"
                        class="rounded-full border px-2.5 py-0.5 text-xs"
                        style="border-color: var(--border-subtle); color: var(--text-base)"
                    >
                        {{ skill }}
                    </li>
                </ul>

                <a
                    v-if="person.linkedin"
                    :href="person.linkedin"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-auto inline-flex items-center gap-1.5 pt-4 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                >
                    <Linkedin class="h-4 w-4" aria-hidden="true" />
                    LinkedIn<span class="sr-only">: {{ person.name }}</span>
                </a>
            </li>
        </ul>
    </section>
</template>
