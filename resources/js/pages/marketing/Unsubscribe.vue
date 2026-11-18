<script setup>
/**
 * Stopping greetings and announcements, from the link in one (§8). One button
 * rather than stopping on open, because mail scanners open links; it can be
 * undone here too, unless the address was stopped because mail to it bounced.
 */
import { router } from '@inertiajs/vue3';
import { MailCheck, MailX } from 'lucide-vue-next';

import PublicLayout from '@/layouts/PublicLayout.vue';
import Seo from '@/components/Seo.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    email: { type: String, required: true },
    stopped: { type: Boolean, default: false },
    canResume: { type: Boolean, default: true },
    stopUrl: { type: String, required: true },
    resumeUrl: { type: String, required: true },
    seo: { type: Object, required: true },
});

const stop = () => router.post(props.stopUrl, {}, { preserveScroll: true });
const resume = () => router.post(props.resumeUrl, {}, { preserveScroll: true });
</script>

<template>
    <Seo :seo="seo" />

    <PublicLayout>
        <section class="mx-auto max-w-lg px-4 py-16 text-center sm:py-24">
            <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl" style="background: var(--surface-sunken)">
                <component :is="stopped ? MailX : MailCheck" class="h-6 w-6" style="color: var(--text-muted)" aria-hidden="true" />
            </span>

            <template v-if="!stopped">
                <h1 class="mt-5 text-2xl font-semibold">Stop greetings and announcements?</h1>
                <p class="mt-3 text-sm" style="color: var(--text-muted)">
                    To {{ email }}. Messages about an account you hold with us, such as sign in codes, receipts and reminders, still come.
                </p>
                <UiButton class="mt-7" size="lg" @click="stop">Stop them</UiButton>
            </template>

            <template v-else>
                <h1 class="mt-5 text-2xl font-semibold">You will get no more greetings or announcements</h1>
                <p class="mt-3 text-sm" style="color: var(--text-muted)">
                    Nothing more of that kind goes to {{ email }}, from now on. Messages about an account you hold with us still come.
                </p>
                <UiButton v-if="canResume" class="mt-7" variant="secondary" @click="resume">Changed your mind? Get them again</UiButton>
            </template>
        </section>
    </PublicLayout>
</template>
