<script setup>
/**
 * The client's landing screen.
 *
 * It opens with what is waiting on them, not with four large numbers. A number
 * tells you how things are; it does not tell you what to do next, and next is
 * the reason somebody signed in.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight, FileText, CreditCard, RefreshCw, AlertTriangle,
    FolderKanban, LifeBuoy, Files, CheckCircle2,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

defineProps({
    projects: { type: Array, default: () => [] },
    waitingOnYou: { type: Array, default: () => [] },
    timeline: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const icons = {
    proposal: FileText,
    payment: CreditCard,
    renewal: RefreshCw,
    overdue: AlertTriangle,
};
</script>

<template>
    <Head title="Your dashboard" />

    <AppLayout title="Overview">
        <div class="mx-auto max-w-7xl space-y-6">
            <!-- ----------------------------------------------- waiting on you -->
            <section v-if="waitingOnYou.length">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Waiting on you
                </h2>

                <ul class="grid gap-3 sm:grid-cols-2">
                    <li v-for="(item, index) in waitingOnYou" :key="`${item.kind}-${index}`">
                        <Link
                            :href="item.href"
                            class="flex h-full items-start gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition
                                   hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]"
                            :style="{ borderColor: item.urgent ? 'var(--color-warn-500)' : 'var(--border-subtle)' }"
                        >
                            <span
                                class="mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
                                :style="{
                                    background: item.urgent ? 'var(--color-warn-50, #fffbeb)' : 'var(--surface-sunken)',
                                    color: item.urgent ? 'var(--color-warn-600)' : 'var(--text-muted)',
                                }"
                            >
                                <component :is="icons[item.kind] ?? FileText" class="h-4 w-4" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{ item.title }}</span>
                                <span class="block text-xs" style="color: var(--text-muted)">{{ item.detail }}</span>
                            </span>

                            <span class="flex shrink-0 items-center gap-1 text-xs font-medium" style="color: var(--color-brand-600)">
                                {{ item.action }}
                                <ArrowRight class="h-3 w-3" />
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>

            <section
                v-else
                class="flex items-center gap-3 rounded-[var(--radius-card)] border px-4 py-3.5"
                style="border-color: var(--border-subtle); background: var(--surface)"
            >
                <CheckCircle2 class="h-5 w-5" style="color: var(--color-signal-500)" />
                <p class="text-sm">Nothing needs your attention right now.</p>
            </section>

            <!-- ------------------------------------------------------- totals -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Active projects" :value="totals.projects ?? 0" href="/client/projects" />
                <StatTile label="Open tickets" :value="totals.openTickets ?? 0" href="/client/support" />
                <StatTile label="Documents" :value="totals.documents ?? 0" href="/client/documents" />
                <StatTile
                    label="Outstanding"
                    :value="totals.due ?? '₹0.00'"
                    :tone="totals.due && totals.due !== '₹0.00' ? 'warning' : 'neutral'"
                    href="/client/payments"
                />
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- ---------------------------------------------------- work -->
                <div class="lg:col-span-2">
                    <UiCard>
                        <template #header>
                            <div class="flex items-center justify-between gap-3">
                                <h2 class="flex items-center gap-2 text-base font-semibold">
                                    <FolderKanban class="h-4 w-4" style="color: var(--text-muted)" />
                                    Your projects
                                </h2>
                                <UiButton href="/client/projects" variant="ghost" size="xs">
                                    See all
                                    <template #trailing><ArrowRight class="h-3 w-3" /></template>
                                </UiButton>
                            </div>
                        </template>

                        <UiEmptyState
                            v-if="!projects.length"
                            :icon="FolderKanban"
                            title="No active projects"
                            description="Anything we are building for you will appear here."
                        />

                        <ul v-else class="divide-y" style="border-color: var(--border-subtle)">
                            <li v-for="project in projects" :key="project.id" class="py-3.5 first:pt-0 last:pb-0">
                                <Link :href="`/client/projects/${project.id}`" class="block">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm font-semibold">{{ project.name }}</span>
                                        <span class="flex items-center gap-1.5">
                                            <UiBadge v-if="project.overdue" tone="warning" size="sm">past target</UiBadge>
                                            <UiBadge size="sm">{{ project.statusLabel }}</UiBadge>
                                        </span>
                                    </div>

                                    <div class="mt-2">
                                        <UiProgress
                                            :value="project.progress"
                                            :label="project.phase || 'In progress'"
                                            size="sm"
                                        />
                                    </div>

                                    <p v-if="project.targetDate" class="mt-1.5 text-xs" style="color: var(--text-muted)">
                                        Target {{ project.targetDate }}
                                    </p>
                                </Link>
                            </li>
                        </ul>
                    </UiCard>
                </div>

                <!-- ------------------------------------------------ timeline -->
                <UiCard>
                    <template #header>
                        <h2 class="text-base font-semibold">Latest updates</h2>
                    </template>

                    <UiEmptyState
                        v-if="!timeline.length"
                        :icon="Files"
                        title="Nothing posted yet"
                        description="Progress notes from the team will show up here."
                    />

                    <ol v-else class="space-y-4">
                        <li v-for="entry in timeline" :key="entry.id" class="relative pl-5">
                            <span
                                class="absolute left-0 top-1.5 h-2 w-2 rounded-full"
                                style="background: var(--color-brand-500)"
                            />
                            <Link :href="`/client/projects/${entry.projectId}`" class="block">
                                <p class="text-xs font-semibold" style="color: var(--text-strong)">
                                    {{ entry.title || entry.project }}
                                </p>
                                <p class="mt-0.5 text-xs" style="color: var(--text-muted)">{{ entry.body }}</p>
                                <p class="mt-1 text-[0.7rem]" style="color: var(--text-muted)">
                                    {{ entry.author }} · {{ entry.at }}
                                </p>
                            </Link>
                        </li>
                    </ol>
                </UiCard>
            </div>

            <!-- ------------------------------------------------ quick links -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <UiButton href="/client/documents" variant="secondary" block>
                    <template #leading><Files class="h-4 w-4" /></template>
                    Documents
                </UiButton>
                <UiButton href="/client/support" variant="secondary" block>
                    <template #leading><LifeBuoy class="h-4 w-4" /></template>
                    Support
                </UiButton>
                <UiButton href="/client/transactions" variant="secondary" block>
                    <template #leading><CreditCard class="h-4 w-4" /></template>
                    Transactions
                </UiButton>
                <UiButton href="/client/reports" variant="secondary" block>
                    <template #leading><FileText class="h-4 w-4" /></template>
                    Reports
                </UiButton>
            </div>
        </div>
    </AppLayout>
</template>
