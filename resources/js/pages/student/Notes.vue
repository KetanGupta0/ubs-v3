<script setup>
/**
 * Every note and bookmark a student has made, newest first, grouped by course.
 * Only the student can read these.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { BookmarkCheck, Lock, Search } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiTabs from '@/components/UI/UiTabs.vue';

const props = defineProps({
    notes: { type: Array, default: () => [] },
    private: { type: Boolean, default: false },
});

const show = ref('all');
const query = ref('');

const tabs = computed(() => [
    { value: 'all', label: `All (${props.notes.length})` },
    { value: 'bookmarks', label: `Bookmarked (${props.notes.filter((note) => note.bookmarked).length})` },
]);

const visible = computed(() => {
    const words = query.value.trim().toLowerCase();

    return props.notes.filter((note) => (show.value === 'all' || note.bookmarked)
        && (!words || [note.lesson, note.course, note.module, note.body].join(' ').toLowerCase().includes(words)));
});

const byCourse = computed(() => {
    const groups = new Map();
    for (const note of visible.value) {
        if (!groups.has(note.courseId)) groups.set(note.courseId, { course: note.course, notes: [] });
        groups.get(note.courseId).notes.push(note);
    }
    return [...groups.values()];
});
</script>

<template>
    <Head title="My notes" />

    <AppLayout title="My notes" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'My notes' }]">
        <div class="mx-auto max-w-3xl">
            <PageHeader title="My notes" description="What you wrote and bookmarked on your lessons. Only you can read these.">
                <template #actions>
                    <span class="flex items-center gap-1 text-xs" style="color: var(--text-muted)"><Lock class="h-3 w-3" /> Private</span>
                </template>
            </PageHeader>

            <UiEmptyState
                v-if="private"
                title="Private to the student"
                description="Notes are not shown while viewing the platform as somebody."
            />

            <template v-else-if="notes.length">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <UiTabs v-model="show" :tabs="tabs" />
                    <div class="relative sm:w-64">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2" style="color: var(--text-muted)" />
                        <UiInput v-model="query" type="search" class="pl-9" placeholder="Search your notes" aria-label="Search your notes" />
                    </div>
                </div>

                <UiEmptyState v-if="!byCourse.length" title="Nothing matches" />

                <section v-for="group in byCourse" :key="group.course" class="mb-6">
                    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">{{ group.course }}</h2>
                    <ul class="space-y-3">
                        <li v-for="note in group.notes" :key="note.id">
                            <UiCard padding="p-4">
                                <Link :href="note.href" class="flex items-start justify-between gap-3 hover:underline">
                                    <span class="min-w-0">
                                        <span class="block font-medium" style="color: var(--text-strong)">{{ note.lesson }}</span>
                                        <span class="block text-xs" style="color: var(--text-muted)">{{ note.module ? `${note.module} · ` : '' }}{{ note.updated }}</span>
                                    </span>
                                    <BookmarkCheck v-if="note.bookmarked" class="h-4 w-4 shrink-0 text-brand-500" aria-label="Bookmarked" />
                                </Link>
                                <p v-if="note.body" class="mt-2 whitespace-pre-line text-sm">{{ note.body }}</p>
                            </UiCard>
                        </li>
                    </ul>
                </section>
            </template>

            <UiEmptyState
                v-else
                title="No notes yet"
                description="Write a note or bookmark a lesson from the lesson page, and it collects here."
            />
        </div>
    </AppLayout>
</template>
