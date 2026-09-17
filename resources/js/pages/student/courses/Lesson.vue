<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft, ArrowRight, CheckCircle2, Circle, Clock, Download,
    ExternalLink, FileText, Film, File, FileSpreadsheet, Presentation,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    course: { type: Object, required: true },
    module: { type: Object, required: true },
    lesson: { type: Object, required: true },
    materials: { type: Array, default: () => [] },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
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
                    <p v-if="lesson.duration" class="mt-1 flex items-center gap-1.5 text-sm" style="color: var(--text-muted)">
                        <Clock class="h-3.5 w-3.5" />{{ lesson.duration }}
                    </p>
                </div>

                <UiButton :href="`/student/courses/${course.id}`" variant="ghost" size="sm">
                    <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                    All lessons
                </UiButton>
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
