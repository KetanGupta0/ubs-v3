<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watchEffect } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowRight, Bookmark, BookmarkCheck, CheckCircle2, Circle, Clock, Download,
    ExternalLink, FileText, Film, File, FileSpreadsheet, Lock, MessageCircleQuestion, NotebookPen, PlayCircle, Presentation,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiTextarea from '@/components/UI/UiTextarea.vue';

const props = defineProps({
    course: { type: Object, required: true },
    module: { type: Object, required: true },
    lesson: { type: Object, required: true },
    materials: { type: Array, default: () => [] },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    note: { type: Object, default: () => ({ body: '', bookmarked: false, private: true }) },
    timeSpent: { type: String, default: null },
    /** Recordings of this batch's classes that taught this lesson. */
    recordings: { type: Array, default: () => [] },
    /** Questions from this student's batch on this lesson. */
    doubts: { type: Array, default: () => [] },
});

const icons = {
    pdf: FileText, video: Film, slides: Presentation, sheet: FileSpreadsheet, link: ExternalLink, file: File,
};

/**
 * Turn a watch URL into an embeddable one.
 *
 * Only for the two hosts we actually use. Anything else is left as a link
 * rather than guessed at, because an iframe pointed at a URL that does not
 * allow framing renders as a blank rectangle.
 */
const embedUrl = computed(() => {
    const url = props.lesson.videoUrl;

    if (!url) return null;

    const youtube = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]{11})/);
    if (youtube) return `https://www.youtube.com/embed/${youtube[1]}`;

    const vimeo = url.match(/vimeo\.com\/(\d+)/);
    if (vimeo) return `https://player.vimeo.com/video/${vimeo[1]}`;

    return null;
});

/* ------------------------------------------------ note and bookmark */
const base = computed(() => `/student/courses/${props.course.id}/lessons/${props.lesson.id}`);
const noteForm = useForm({ body: props.note.body, bookmarked: props.note.bookmarked });
const saved = ref(false);

function saveNote() {
    noteForm.put(`${base.value}/note`, {
        preserveScroll: true,
        preserveState: true,
        only: ['note'],
        onSuccess: () => {
            noteForm.defaults();
            saved.value = true;
            setTimeout(() => { saved.value = false; }, 2500);
        },
    });
}

function toggleBookmark() {
    noteForm.bookmarked = !noteForm.bookmarked;
    saveNote();
}

/*
 * Time on the lesson, counted only while the page is visible and sent every
 * two minutes, plus whatever is left when the page is hidden or left. The
 * server caps each beat and the total; it is an indication, not a stopwatch.
 */
let visibleSince = null;
let pending = 0;
let timer = null;

function accumulate() {
    if (visibleSince !== null) {
        pending += Date.now() - visibleSince;
        visibleSince = Date.now();
    }
}

function flush() {
    accumulate();
    const seconds = Math.round(pending / 1000);
    if (seconds < 5) return;
    pending = 0;

    fetch(`${base.value}/time`, {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ seconds }),
    }).catch(() => {});
}

function onVisibility() {
    if (document.visibilityState === 'visible') {
        visibleSince = Date.now();
    } else {
        flush();
        visibleSince = null;
    }
}

onMounted(() => {
    visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    timer = setInterval(flush, 120000);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('pagehide', flush);
});

onBeforeUnmount(() => {
    flush();
    clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('pagehide', flush);
});

/* ------------------------------------------------------- questions */
const askForm = useForm({ body: '' });
// One follow-up form per question of the student's own, made before render.
const replyForms = reactive({});
watchEffect(() => {
    for (const doubt of props.doubts) {
        if (doubt.mine && !replyForms[doubt.id]) replyForms[doubt.id] = useForm({ body: '' });
    }
});

function ask() {
    askForm.post(`${base.value}/doubts`, {
        preserveScroll: true,
        onSuccess: () => askForm.reset(),
    });
}

function follow(doubt) {
    replyForms[doubt.id].post(`${base.value}/doubts/${doubt.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => replyForms[doubt.id].reset(),
    });
}

function toggleComplete() {
    router.post(
        `/student/courses/${props.course.id}/lessons/${props.lesson.id}/complete`,
        { undo: props.lesson.completed },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="lesson.title" />

    <AppLayout
        :title="lesson.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/student' },
            { label: course.title, href: `/student/courses/${course.id}` },
            { label: module.title },
        ]"
    >
        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">
                        {{ module.title }}
                    </p>
                    <h1 class="mt-1 text-xl font-semibold sm:text-2xl">{{ lesson.title }}</h1>
                    <p v-if="lesson.duration || timeSpent" class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm" style="color: var(--text-muted)">
                        <span v-if="lesson.duration" class="flex items-center gap-1.5"><Clock class="h-3.5 w-3.5" />{{ lesson.duration }}</span>
                        <span v-if="timeSpent">You have spent {{ timeSpent.toLowerCase() }} here</span>
                    </p>
                </div>

                <div class="flex gap-2">
                    <UiButton
                        v-if="note.private"
                        variant="ghost"
                        size="sm"
                        :aria-pressed="noteForm.bookmarked"
                        @click="toggleBookmark"
                    >
                        <template #leading>
                            <component :is="noteForm.bookmarked ? BookmarkCheck : Bookmark" class="h-3.5 w-3.5" :class="noteForm.bookmarked ? 'text-brand-500' : ''" />
                        </template>
                        {{ noteForm.bookmarked ? 'Bookmarked' : 'Bookmark' }}
                    </UiButton>
                    <UiButton :href="`/student/courses/${course.id}`" variant="ghost" size="sm">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All lessons
                    </UiButton>
                </div>
            </div>

            <!-- ------------------------------------------------- the video -->
            <div
                v-if="embedUrl"
                class="overflow-hidden rounded-[var(--radius-card)] border"
                style="border-color: var(--border-subtle)"
            >
                <div class="relative w-full" style="padding-top: 56.25%">
                    <iframe
                        :src="embedUrl"
                        class="absolute inset-0 h-full w-full"
                        :title="lesson.title"
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture"
                        allowfullscreen
                    />
                </div>
            </div>

            <UiCard v-else-if="lesson.videoUrl">
                <UiButton :href="lesson.videoUrl" :inertia="false" target="_blank" variant="secondary">
                    <template #leading><Film class="h-4 w-4" /></template>
                    Watch the recording
                </UiButton>
            </UiCard>

            <!-- ------------------------------------- class recordings -->
            <UiCard v-if="recordings.length">
                <template #header><h2 class="text-base font-semibold">Your batch's class on this</h2></template>
                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="recording in recordings" :key="recording.id" class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                        <PlayCircle class="h-4 w-4 shrink-0 text-brand-500" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm">{{ recording.title }}</span>
                            <span class="block text-xs" style="color: var(--text-muted)">{{ recording.at }}</span>
                        </span>
                        <UiButton :href="recording.url" :inertia="false" target="_blank" variant="ghost" size="xs">
                            <template #leading><ExternalLink class="h-3 w-3" /></template>
                            Watch
                        </UiButton>
                    </li>
                </ul>
            </UiCard>

            <!-- ----------------------------------------------- the content -->
            <UiCard v-if="lesson.content">
                <div class="whitespace-pre-line text-sm leading-relaxed" style="color: var(--text-base)">
                    {{ lesson.content }}
                </div>
            </UiCard>

            <!-- --------------------------------------------- the handouts -->
            <UiCard v-if="materials.length">
                <template #header><h2 class="text-base font-semibold">For this lesson</h2></template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li v-for="material in materials" :key="material.id"
                        class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                        <component :is="icons[material.kind] ?? File" class="h-4 w-4 shrink-0"
                                   style="color: var(--text-muted)" />

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm">{{ material.title }}</span>
                            <span v-if="material.description || material.size" class="block text-xs"
                                  style="color: var(--text-muted)">
                                {{ [material.description, material.size].filter(Boolean).join(' · ') }}
                            </span>
                        </span>

                        <UiButton
                            v-if="material.isLink"
                            :href="material.url"
                            :inertia="false"
                            target="_blank"
                            variant="ghost"
                            size="xs"
                        >
                            <template #leading><ExternalLink class="h-3 w-3" /></template>
                            Open
                        </UiButton>
                        <UiButton
                            v-else-if="material.downloadable"
                            :href="`/student/courses/${course.id}/materials/${material.id}`"
                            :inertia="false"
                            variant="ghost"
                            size="xs"
                        >
                            <template #leading><Download class="h-3 w-3" /></template>
                            Download
                        </UiButton>
                        <span v-else class="text-xs" style="color: var(--text-muted)">view only</span>
                    </li>
                </ul>
            </UiCard>

            <!-- ---------------------------------------------- my own note -->
            <UiCard>
                <template #header>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <NotebookPen class="h-4 w-4" style="color: var(--text-muted)" />
                            Your notes
                        </h2>
                        <span class="flex items-center gap-1 text-xs" style="color: var(--text-muted)">
                            <Lock class="h-3 w-3" /> Only you can read these
                        </span>
                    </div>
                </template>

                <p v-if="!note.private" class="text-sm" style="color: var(--text-muted)">
                    A student's notes are private, so they are not shown while viewing as them.
                </p>
                <form v-else class="space-y-3" @submit.prevent="saveNote">
                    <UiTextarea
                        v-model="noteForm.body"
                        :rows="5"
                        aria-label="Your notes on this lesson"
                        placeholder="What to remember, what to ask, what to try again."
                    />
                    <p v-if="noteForm.errors.body" class="text-sm text-danger-600">{{ noteForm.errors.body }}</p>
                    <div class="flex items-center justify-between gap-3">
                        <Link href="/student/notes" class="text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">All your notes</Link>
                        <div class="flex items-center gap-3">
                            <span v-if="saved" class="text-xs" style="color: var(--text-muted)" role="status">Saved</span>
                            <UiButton type="submit" variant="secondary" size="sm" :loading="noteForm.processing" :disabled="!noteForm.isDirty">Save note</UiButton>
                        </div>
                    </div>
                </form>
            </UiCard>

            <!-- ------------------------------------------------ questions -->
            <UiCard id="questions">
                <template #header>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <MessageCircleQuestion class="h-4 w-4" style="color: var(--text-muted)" />
                            Questions on this lesson
                        </h2>
                        <span class="text-xs" style="color: var(--text-muted)">Shared with your batch</span>
                    </div>
                </template>

                <ul v-if="doubts.length" class="mb-5 space-y-4">
                    <li v-for="doubt in doubts" :id="`doubt-${doubt.id}`" :key="doubt.id" class="rounded-xl border p-4" style="border-color: var(--border-subtle)">
                        <div class="flex items-center justify-between gap-2 text-xs" style="color: var(--text-muted)">
                            <span><span class="font-medium" style="color: var(--text-strong)">{{ doubt.asker }}</span> asked {{ doubt.at }}</span>
                            <span v-if="doubt.status === 'answered'" class="font-medium text-green-700 dark:text-green-300">Answered</span>
                            <span v-else>Waiting for an answer</span>
                        </div>
                        <p class="mt-2 whitespace-pre-line text-sm">{{ doubt.body }}</p>

                        <ul v-if="doubt.replies.length" class="mt-3 space-y-2 border-l-2 pl-3" style="border-color: var(--border-subtle)">
                            <li v-for="reply in doubt.replies" :key="reply.id">
                                <p class="text-xs" style="color: var(--text-muted)">
                                    <span class="font-medium" :class="reply.fromStaff ? 'text-brand-600 dark:text-brand-400' : ''" :style="reply.fromStaff ? {} : { color: 'var(--text-strong)' }">{{ reply.author }}</span>
                                    <span v-if="reply.fromStaff"> · trainer</span> · {{ reply.at }}
                                </p>
                                <p class="mt-0.5 whitespace-pre-line text-sm">{{ reply.body }}</p>
                            </li>
                        </ul>

                        <form v-if="doubt.mine && replyForms[doubt.id]" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start" @submit.prevent="follow(doubt)">
                            <UiTextarea v-model="replyForms[doubt.id].body" :rows="1" class="flex-1" aria-label="Add to your question" placeholder="Add to your question" />
                            <UiButton type="submit" variant="secondary" size="sm" :loading="replyForms[doubt.id].processing" :disabled="!replyForms[doubt.id].body.trim()">Add</UiButton>
                        </form>
                    </li>
                </ul>
                <p v-else class="mb-4 text-sm" style="color: var(--text-muted)">Nobody on your batch has asked anything here yet.</p>

                <form class="space-y-2" @submit.prevent="ask">
                    <UiTextarea v-model="askForm.body" :rows="3" aria-label="Ask a question" placeholder="Stuck on something in this lesson? Ask it here. Your trainer answers, and your batch can read the answer." />
                    <p v-if="askForm.errors.body" class="text-sm text-danger-600">{{ askForm.errors.body }}</p>
                    <div class="flex justify-end">
                        <UiButton type="submit" size="sm" :loading="askForm.processing" :disabled="!askForm.body.trim()">Ask</UiButton>
                    </div>
                </form>
            </UiCard>

            <!-- ------------------------------------------------ navigation -->
            <UiCard>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <UiButton
                        :variant="lesson.completed ? 'ghost' : 'primary'"
                        @click="toggleComplete"
                    >
                        <template #leading>
                            <component :is="lesson.completed ? CheckCircle2 : Circle" class="h-4 w-4" />
                        </template>
                        {{ lesson.completed ? 'Done — undo' : 'Mark as done' }}
                    </UiButton>

                    <div class="flex gap-2">
                        <UiButton
                            v-if="previous"
                            :href="`/student/courses/${course.id}/lessons/${previous.id}`"
                            variant="secondary"
                            size="sm"
                        >
                            <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                            Previous
                        </UiButton>
                        <UiButton
                            v-if="next"
                            :href="`/student/courses/${course.id}/lessons/${next.id}`"
                            size="sm"
                        >
                            Next
                            <template #trailing><ArrowRight class="h-3.5 w-3.5" /></template>
                        </UiButton>
                    </div>
                </div>
            </UiCard>
        </div>
    </AppLayout>
</template>
