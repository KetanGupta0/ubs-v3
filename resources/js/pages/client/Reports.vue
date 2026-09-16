<script setup>
/**
 * Three questions, answered plainly: how is the work going, what have we spent,
 * and how quickly do problems get dealt with.
 */
import { Head, Link } from '@inertiajs/vue3';
import { Printer } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiProgress from '@/components/UI/UiProgress.vue';
import UiBarChart from '@/components/UI/UiBarChart.vue';

defineProps({
    projects: { type: Object, required: true },
    spend: { type: Object, required: true },
    support: { type: Object, required: true },
});
</script>

<template>
    <Head title="Reports" />

    <AppLayout title="Reports" :breadcrumbs="[{ label: 'Overview', href: '/client' }, { label: 'Reports' }]">
        <div class="mx-auto max-w-5xl space-y-6">
            <PageHeader
                title="Reports"
                description="Where the work stands, what it has cost, and how support is going."
            >
                <template #actions>
                    <UiButton variant="secondary" size="sm" @click="print()">
                        <template #leading><Printer class="h-3.5 w-3.5" /></template>
                        Print
                    </UiButton>
                </template>
            </PageHeader>

            <!-- --------------------------------------------------- projects -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Delivery
                </h2>

                <div class="mb-4 grid gap-3 sm:grid-cols-4">
                    <StatTile label="Projects" :value="projects.total" />
                    <StatTile label="In progress" :value="projects.active" tone="brand" />
                    <StatTile label="Delivered" :value="projects.delivered" tone="success" />
                    <StatTile
                        label="Past target"
                        :value="projects.overdue"
                        :tone="projects.overdue ? 'warning' : 'neutral'"
                    />
                </div>

                <UiCard v-if="projects.rows.length">
                    <ul class="divide-y" style="border-color: var(--border-subtle)">
                        <li v-for="project in projects.rows" :key="project.id" class="py-3 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <Link :href="`/client/projects/${project.id}`" class="text-sm font-medium hover:underline">
                                    {{ project.name }}
                                </Link>
                                <span class="flex items-center gap-2">
                                    <UiBadge v-if="project.overdue" tone="warning" size="sm">past target</UiBadge>
                                    <UiBadge size="sm">{{ project.status }}</UiBadge>
                                </span>
                            </div>
                            <div class="mt-2">
                                <UiProgress :value="project.progress" size="sm" :label="`Target ${project.target}`" />
                            </div>
                        </li>
                    </ul>
                </UiCard>
            </section>

            <!-- ------------------------------------------------------ spend -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Spend
                </h2>

                <div class="mb-4 grid gap-3 sm:grid-cols-3">
                    <StatTile label="Billed" :value="spend.billed" />
                    <StatTile label="Paid" :value="spend.paid" tone="success" />
                    <StatTile label="Outstanding" :value="spend.outstanding" tone="warning" />
                </div>

                <UiCard>
                    <template #header>
                        <h3 class="text-sm font-semibold">Paid over the last twelve months</h3>
                    </template>

                    <UiBarChart
                        :data="spend.byMonth.map((point) => ({
                            label: point.month,
                            value: point.amount,
                            display: point.display,
                        }))"
                        :height="140"
                    />
                </UiCard>
            </section>

            <!-- ---------------------------------------------------- support -->
            <section>
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                    Support
                </h2>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="grid gap-3 sm:grid-cols-3 lg:col-span-2 lg:grid-cols-2">
                        <StatTile label="Tickets raised" :value="support.total" />
                        <StatTile label="Still open" :value="support.open" :tone="support.open ? 'brand' : 'neutral'" />
                        <StatTile
                            label="Past the promised time"
                            :value="support.breached"
                            :tone="support.breached ? 'danger' : 'success'"
                        />
                        <StatTile
                            label="Average time to resolve"
                            :value="support.averageResolutionHours !== null ? `${support.averageResolutionHours} h` : '—'"
                        />
                    </div>

                    <UiCard>
                        <template #header>
                            <h3 class="text-sm font-semibold">By priority</h3>
                        </template>

                        <ul class="space-y-3">
                            <li v-for="row in support.byPriority" :key="row.priority">
                                <UiProgress
                                    :value="support.total ? (row.count / support.total) * 100 : 0"
                                    :label="`${row.priority} (${row.count})`"
                                    :show-value="false"
                                    size="sm"
                                    :tone="row.priority === 'urgent' ? 'danger' : row.priority === 'high' ? 'warning' : 'brand'"
                                />
                            </li>
                        </ul>
                    </UiCard>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
