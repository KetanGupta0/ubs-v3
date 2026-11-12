<script setup>
/**
 * Registrations made with this student's address on a public form.
 *
 * Anybody can type anybody's address into a form, so a registration that
 * found an existing account waits here until its holder says it was them.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Check, X } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    registrations: { type: Array, default: () => [] },
});

const tones = {
    awaiting_confirmation: 'brand',
    awaiting_review: 'warning',
    approved: 'success',
    rejected: 'danger',
    declined: 'neutral',
    received: 'neutral',
};

/** The same statuses, said to the student rather than about them. */
const labels = {
    awaiting_confirmation: 'Waiting for you',
    awaiting_review: 'Being reviewed',
    approved: 'On the course',
    rejected: 'Not accepted',
    declined: 'Withdrawn',
    received: 'Received',
};

const confirm = (registration) => router.post(`/student/registrations/${registration.id}/confirm`, {}, { preserveScroll: true });

function decline(registration, notMe) {
    const question = notMe
        ? 'Say this was not you? Nothing is added to your account.'
        : `Withdraw your registration for ${registration.course}?`;

    if (window.confirm(question)) {
        router.post(`/student/registrations/${registration.id}/decline`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Registrations" />

    <AppLayout title="Registrations" :breadcrumbs="[{ label: 'Dashboard', href: '/student' }, { label: 'Registrations' }]">
        <div class="mx-auto max-w-3xl">
            <PageHeader
                title="Registrations"
                description="Courses your address was registered for through one of our registration forms."
            />

            <UiEmptyState
                v-if="!registrations.length"
                title="Nothing here"
                description="When your address is used on a registration form, it shows here."
            />

            <ul v-else class="space-y-3">
                <li v-for="registration in registrations" :key="registration.id">
                    <UiCard padding="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold" style="color: var(--text-strong)">{{ registration.course }}</p>
                                <p class="mt-0.5 text-sm" style="color: var(--text-muted)">
                                    {{ registration.batch ? `${registration.batch} · ` : '' }}{{ registration.fee }} · registered {{ registration.submitted }}
                                </p>
                            </div>
                            <UiBadge :tone="tones[registration.status] ?? 'neutral'" size="sm" dot>{{ labels[registration.status] ?? registration.statusLabel }}</UiBadge>
                        </div>

                        <template v-if="registration.status === 'awaiting_confirmation'">
                            <p class="mt-3 text-sm">
                                Was this you? Nothing goes on your account until you say so.
                                {{ registration.reviewed ? 'Once you confirm, the team reviews it.' : 'Once you confirm, you are on the course.' }}
                            </p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <UiButton size="sm" @click="confirm(registration)">
                                    <template #leading><Check class="h-3.5 w-3.5" /></template>
                                    Yes, it was me
                                </UiButton>
                                <UiButton variant="ghost" size="sm" @click="decline(registration, true)">
                                    <template #leading><X class="h-3.5 w-3.5" /></template>
                                    Not me
                                </UiButton>
                            </div>
                        </template>

                        <template v-else-if="registration.status === 'awaiting_review'">
                            <p class="mt-3 text-sm" style="color: var(--text-muted)">The team is looking at it, and will write to you once it is decided.</p>
                            <UiButton class="mt-3" variant="ghost" size="sm" @click="decline(registration, false)">Withdraw</UiButton>
                        </template>

                        <p v-else-if="registration.status === 'rejected' && registration.reason" class="mt-3 text-sm">{{ registration.reason }}</p>

                        <Link
                            v-else-if="registration.enrolled"
                            :href="`/student/courses/${registration.courseId}`"
                            class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >
                            Open the course
                            <ArrowRight class="h-3.5 w-3.5" />
                        </Link>
                    </UiCard>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
