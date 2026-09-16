<script setup>
/**
 * Shared placeholder for the three role dashboards.
 *
 * Phases 3, 4 and 5 replace the body of each. Until then this gives sign in
 * somewhere real to land, and nudges the account towards being fully set up.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ShieldCheck, MailWarning, ArrowRight, Sparkles } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';

const props = defineProps({
    title: { type: String, required: true },
    intro: { type: String, required: true },
    user: { type: Object, required: true },
    /** What lands in this dashboard, and in which phase. */
    upcoming: { type: Array, default: () => [] },
});

/** How many setup nudges are still worth showing. */
const outstanding = computed(
    () => (props.user.emailVerified ? 0 : 1) + (props.user.twoFactorEnabled ? 0 : 1),
);
</script>

<template>
    <Head :title="title" />

    <AppLayout :title="title">
        <div class="mx-auto max-w-5xl space-y-5">
            <UiCard padding="p-6 sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-sm" style="color: var(--text-muted)">Welcome back</p>
                        <h2 class="mt-1 text-2xl font-semibold">{{ user.name }}</h2>
                        <p class="mt-2 max-w-xl text-sm" style="color: var(--text-muted)">{{ intro }}</p>
                    </div>

                    <div class="w-full text-left sm:w-auto sm:text-right">
                        <UiBadge tone="brand" dot>{{ user.role }}</UiBadge>
                        <p v-if="user.lastLoginAt" class="mt-2 text-xs" style="color: var(--text-muted)">
                            Last signed in {{ user.lastLoginAt }}
                        </p>
                    </div>
                </div>
            </UiCard>

            <!-- Only shown while something is actually outstanding. -->
            <div
                v-if="outstanding"
                class="grid gap-4"
                :class="outstanding === 2 ? 'sm:grid-cols-2' : 'grid-cols-1'"
            >
                <UiCard v-if="!user.emailVerified">
                    <div class="flex items-start gap-3">
                        <MailWarning class="mt-0.5 h-5 w-5 shrink-0 text-warn-500" aria-hidden="true" />
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">Confirm your email</h3>
                            <p class="mt-1 text-sm" style="color: var(--text-muted)">
                                It is how we send invoices, receipts and class reminders.
                            </p>
                            <UiButton class="mt-3" size="sm" variant="secondary" href="/verify-email">
                                Confirm now
                            </UiButton>
                        </div>
                    </div>
                </UiCard>

                <UiCard v-if="!user.twoFactorEnabled">
                    <div class="flex items-start gap-3">
                        <ShieldCheck class="mt-0.5 h-5 w-5 shrink-0 text-brand-500" aria-hidden="true" />
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold">Add two factor authentication</h3>
                            <p class="mt-1 text-sm" style="color: var(--text-muted)">
                                A code from your phone on top of your password. Takes a minute.
                            </p>
                            <UiButton class="mt-3" size="sm" variant="secondary" href="/settings/security">
                                Set it up
                            </UiButton>
                        </div>
                    </div>
                </UiCard>
            </div>

            <UiCard>
                <template #header>
                    <div class="flex items-center gap-2">
                        <Sparkles class="h-4 w-4 text-accent-500" aria-hidden="true" />
                        <h3 class="text-base font-semibold">Coming to this dashboard</h3>
                    </div>
                </template>

                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="item in upcoming"
                        :key="item.label"
                        class="rounded-xl border p-4"
                        style="border-color: var(--border-subtle)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-medium" style="color: var(--text-strong)">{{ item.label }}</span>
                            <UiBadge size="sm">{{ item.phase }}</UiBadge>
                        </div>
                        <p class="mt-1 text-sm" style="color: var(--text-muted)">{{ item.detail }}</p>
                    </li>
                </ul>
            </UiCard>

            <p class="text-center text-sm" style="color: var(--text-muted)">
                Manage your password, devices and two factor settings on the
                <Link href="/settings/security" class="font-medium text-brand-600 hover:underline dark:text-brand-400">
                    security page <ArrowRight class="inline h-3 w-3" />
                </Link>
            </p>
        </div>
    </AppLayout>
</template>
