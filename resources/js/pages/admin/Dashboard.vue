<script setup>
/**
 * The admin dashboard.
 *
 * Ordered by what needs doing rather than by what is impressive. The attention
 * list sits above the totals, because a count of everything ever rarely makes
 * anyone act, and an enquiry that has been sitting for four days does.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle, ArrowRight, CalendarDays, Inbox, Users, Briefcase,
    Package, GraduationCap, History, Mail,
} from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';

const props = defineProps({
    metrics: { type: Object, default: () => ({}) },
    attention: { type: Array, default: () => [] },
    mail: { type: Object, required: true },
    recentLeads: { type: Array, default: () => [] },
    upcomingBatches: { type: Array, default: () => [] },
    recentActivity: { type: Array, default: () => [] },
});

const statusTones = {
    new: 'brand',
    contacted: 'accent',
    qualified: 'warning',
    converted: 'success',
    lost: 'neutral',
};

/*
 * Mail against the plan's daily limit (X15). The fill carries how close the day
 * is to the limit; the track is a paler step of the same colour, so the state
 * reads across the whole bar. A badge with words says it too, never colour alone.
 */
const mailFill = computed(() => ({
    ok: 'var(--color-brand-500)',
    low: 'var(--color-warn-500)',
    used_up: 'var(--color-danger-500)',
}[props.mail.state] ?? 'var(--color-brand-500)'));

const mailUsed = computed(() => Math.min(100, Math.round((props.mail.sent / Math.max(1, props.mail.limit)) * 100)));

const attentionTones = {
    danger: { border: 'var(--color-danger-500)', wash: 'color-mix(in oklab, var(--color-danger-500) 8%, transparent)' },
    warning: { border: 'var(--color-warn-500)', wash: 'color-mix(in oklab, var(--color-warn-500) 8%, transparent)' },
};
</script>

<template>
    <Head title="Admin" />

    <AppLayout title="Dashboard">
        <div>
            <PageHeader
                title="Dashboard"
                description="What needs doing, then how things stand."
            >
                <template #actions>
                    <UiButton href="/admin/clients/new" size="sm">Add a client</UiButton>
                    <UiButton href="/admin/leads" size="sm" variant="secondary">Open the inbox</UiButton>
                </template>
            </PageHeader>

            <!-- ----------------------------------------------------- attention -->
            <section v-if="attention.length" class="mb-6 space-y-3">
                <div
                    v-for="item in attention"
                    :key="item.title"
                    class="flex flex-wrap items-center justify-between gap-4 rounded-[var(--radius-card)] border p-4"
                    :style="{
                        borderColor: attentionTones[item.tone]?.border ?? 'var(--border-subtle)',
                        background: attentionTones[item.tone]?.wash ?? 'transparent',
                    }"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <AlertTriangle
                            class="mt-0.5 h-4.5 w-4.5 shrink-0"
                            :style="{ color: attentionTones[item.tone]?.border }"
                            aria-hidden="true"
                        />
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">{{ item.title }}</p>
                            <p class="mt-0.5 text-sm" style="color: var(--text-muted)">{{ item.body }}</p>
                        </div>
                    </div>

                    <UiButton :href="item.href" size="sm" variant="secondary" class="shrink-0">
                        {{ item.action }}
                        <template #trailing><ArrowRight class="h-3.5 w-3.5" /></template>
                    </UiButton>
                </div>
            </section>

            <!-- --------------------------------------------------------- stats -->
            <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Open enquiries"
                    :value="metrics.openLeads ?? 0"
                    :hint="`${metrics.newLeadsThisWeek ?? 0} arrived this week`"
                    href="/admin/leads"
                    :tone="metrics.openLeads ? 'brand' : 'neutral'"
                />
                <StatTile label="Clients" :value="metrics.clients ?? 0" href="/admin/clients" />
                <StatTile label="Students" :value="metrics.students ?? 0" href="/admin/students" />
                <StatTile
                    label="Upcoming batches"
                    :value="metrics.upcomingBatches ?? 0"
                    href="/admin/batches"
                />
            </section>

            <!-- ---------------------------------------------------- delivery -->
            <section class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Projects in delivery"
                    :value="metrics.activeProjects ?? 0"
                    href="/admin/projects"
                    :tone="metrics.activeProjects ? 'brand' : 'neutral'"
                />
                <StatTile
                    label="Open tickets"
                    :value="metrics.openTickets ?? 0"
                    href="/admin/tickets"
                    :tone="metrics.openTickets ? 'warning' : 'neutral'"
                />
                <StatTile
                    label="Outstanding"
                    :value="metrics.outstanding ?? '₹0.00'"
                    href="/admin/billing"
                />
                <StatTile
                    label="Collected this month"
                    :value="metrics.collectedThisMonth ?? '₹0.00'"
                    tone="success"
                    href="/admin/invoices"
                />
            </section>

            <!-- -------------------------------------------------------- mail -->
            <section
                class="mb-6 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4"
                style="border-color: var(--border-subtle)"
                aria-labelledby="mail-today"
            >
                <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                    <div>
                        <h2 id="mail-today" class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide" style="color: var(--text-muted)">
                            <Mail class="h-3.5 w-3.5" aria-hidden="true" />
                            Mail left today
                        </h2>
                        <p class="mt-1.5 flex flex-wrap items-baseline gap-x-2">
                            <span class="text-2xl font-semibold" style="color: var(--text-strong)">{{ mail.left }}</span>
                            <span class="text-sm" style="color: var(--text-muted)">of the {{ mail.limit }} your plan allows</span>
                            <UiBadge v-if="mail.state !== 'ok'" :tone="mail.state === 'used_up' ? 'danger' : 'warning'" size="sm">
                                <AlertTriangle class="mr-1 h-3 w-3" aria-hidden="true" />
                                {{ mail.state === 'used_up' ? 'Used up' : 'Running low' }}
                            </UiBadge>
                        </p>
                    </div>

                    <Link href="/admin/settings?tab=mail" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                        Change the limit
                    </Link>
                </div>

                <div
                    class="mt-3 h-2 overflow-hidden rounded-full"
                    :style="{ background: `color-mix(in oklab, ${mailFill} 18%, var(--surface))` }"
                    role="meter"
                    aria-label="Mail sent today"
                    aria-valuemin="0"
                    :aria-valuemax="mail.limit"
                    :aria-valuenow="Math.min(mail.sent, mail.limit)"
                    :aria-valuetext="`${mail.sent} of ${mail.limit} sent, ${mail.left} left`"
                >
                    <div class="h-full rounded-full" :style="{ width: `${mailUsed}%`, background: mailFill }" />
                </div>

                <p class="mt-2 text-xs" style="color: var(--text-muted)">
                    {{ mail.sent }} sent since midnight, {{ mail.promotional }} of them greetings and announcements<template v-if="mail.refused">, {{ mail.refused }} refused by the mail server</template>.
                    <template v-if="mail.promotionalLeft > 0">
                        Greetings and announcements can send {{ mail.promotionalLeft }} more today; the last {{ mail.reserve }} are kept for sign in codes, receipts and reminders.
                    </template>
                    <template v-else-if="mail.left > 0">
                        Greetings and announcements wait for tomorrow, so what is left stays for sign in codes, receipts and reminders.
                    </template>
                    <template v-else>Greetings and announcements wait for tomorrow.</template>
                </p>
            </section>

            <div class="grid gap-5 lg:grid-cols-3">
                <!-- --------------------------------------------- recent leads -->
                <UiCard class="lg:col-span-2">
                    <template #header>
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="flex items-center gap-2 text-base font-semibold">
                                <Inbox class="h-4 w-4" style="color: var(--text-muted)" aria-hidden="true" />
                                Latest enquiries
                            </h2>
                            <Link href="/admin/leads" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                                See all
                            </Link>
                        </div>
                    </template>

                    <ul v-if="recentLeads.length" class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="lead in recentLeads" :key="lead.id" class="py-3 first:pt-0 last:pb-0">
                            <Link :href="`/admin/leads/${lead.id}`" class="group flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold group-hover:underline">{{ lead.name }}</span>
                                        <UiBadge :tone="statusTones[lead.status] ?? 'neutral'" size="sm" dot>
                                            {{ lead.status }}
                                        </UiBadge>
                                        <UiBadge v-if="lead.status === 'new' && lead.waitingDays >= 2" tone="danger" size="sm">
                                            waiting {{ lead.waitingDays }} days
                                        </UiBadge>
                                    </p>
                                    <p class="mt-0.5 truncate text-sm" style="color: var(--text-muted)">{{ lead.subject }}</p>
                                </div>

                                <span class="shrink-0 text-right text-xs" style="color: var(--text-muted)">
                                    <span class="block font-mono">{{ lead.reference }}</span>
                                    <span class="block">{{ lead.receivedAt }}</span>
                                </span>
                            </Link>
                        </li>
                    </ul>

                    <UiEmptyState
                        v-else
                        :icon="Inbox"
                        title="No enquiries yet"
                        description="They will appear here the moment someone uses a form on the site."
                    />
                </UiCard>

                <!-- ------------------------------------------ upcoming batches -->
                <UiCard>
                    <template #header>
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <CalendarDays class="h-4 w-4" style="color: var(--text-muted)" aria-hidden="true" />
                            Starting soon
                        </h2>
                    </template>

                    <ul v-if="upcomingBatches.length" class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="batch in upcomingBatches" :key="batch.id" class="py-3 first:pt-0 last:pb-0">
                            <p class="text-sm font-semibold">{{ batch.course }}</p>
                            <p class="mt-0.5 text-xs" style="color: var(--text-muted)">
                                {{ batch.startsOn }} · {{ batch.startsIn }}
                            </p>
                            <p v-if="batch.seatsLeft !== null" class="mt-1">
                                <UiBadge :tone="batch.nearlyFull ? 'warning' : 'neutral'" size="sm">
                                    {{ batch.seatsLeft }} seats left
                                </UiBadge>
                            </p>
                        </li>
                    </ul>

                    <UiEmptyState
                        v-else
                        :icon="CalendarDays"
                        title="Nothing scheduled"
                        description="Create a batch to open enrolment."
                    />
                </UiCard>
            </div>

            <!-- ---------------------------------------------------- shortcuts -->
            <section class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Link
                    v-for="shortcut in [
                        { label: 'Solutions catalogue', href: '/admin/solutions', icon: Package, count: metrics.publishedSolutions, hint: 'live' },
                        { label: 'Courses and internships', href: '/admin/courses', icon: GraduationCap, count: metrics.liveOfferings, hint: 'public' },
                        { label: 'Colleges', href: '/admin/colleges', icon: Briefcase },
                        { label: 'Staff and permissions', href: '/admin/staff', icon: Users },
                    ]"
                    :key="shortcut.href"
                    :href="shortcut.href"
                    class="flex items-center gap-3 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 transition hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]"
                    style="border-color: var(--border-subtle)"
                >
                    <component :is="shortcut.icon" class="h-4.5 w-4.5 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold">{{ shortcut.label }}</span>
                        <span v-if="shortcut.count !== undefined" class="block text-xs" style="color: var(--text-muted)">
                            {{ shortcut.count }} {{ shortcut.hint }}
                        </span>
                    </span>
                    <ArrowRight class="h-4 w-4 shrink-0" style="color: var(--text-muted)" />
                </Link>
            </section>

            <!-- ----------------------------------------------------- activity -->
            <UiCard v-if="recentActivity.length" class="mt-5">
                <template #header>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="flex items-center gap-2 text-base font-semibold">
                            <History class="h-4 w-4" style="color: var(--text-muted)" aria-hidden="true" />
                            Recent changes
                        </h2>
                        <Link href="/admin/audit-log" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
                            Full log
                        </Link>
                    </div>
                </template>

                <ul class="space-y-2 text-sm">
                    <li
                        v-for="entry in recentActivity"
                        :key="entry.id"
                        class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                    >
                        <span style="color: var(--text-base)">
                            <span class="font-medium" style="color: var(--text-strong)">{{ entry.actor }}</span>
                            {{ entry.action.replace(/[._]/g, ' ') }}
                            <span v-if="entry.label" style="color: var(--text-muted)">{{ entry.label }}</span>
                        </span>
                        <span class="text-xs" style="color: var(--text-muted)">{{ entry.at }}</span>
                    </li>
                </ul>
            </UiCard>
        </div>
    </AppLayout>
</template>
