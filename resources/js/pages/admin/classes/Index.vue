<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ClipboardCheck, ExternalLink, PlayCircle, Radio } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';

const props = defineProps({
    table: { type: Object, required: true },
    counts: { type: Object, default: () => ({}) },
});

const statusTones = { scheduled: 'brand', held: 'success', cancelled: 'neutral' };

/* Quick views are plain filtered links, so they can be bookmarked and shared. */
const views = [
    { label: 'Upcoming', query: 'filter[when]=upcoming&sort=scheduled_at', when: 'upcoming' },
    { label: 'Today', query: 'filter[when]=today&sort=scheduled_at', when: 'today' },
    { label: 'Past', query: 'filter[when]=past&sort=-scheduled_at', when: 'past' },
    { label: 'Register not marked', query: 'filter[when]=unmarked&sort=-scheduled_at', when: 'unmarked' },
    { label: 'Cancelled', query: 'filter[status][]=cancelled&sort=-scheduled_at', status: 'cancelled' },
];

const page = usePage();

/*
 * Read back from the parsed query, not the raw text: the table rewrites
 * filter[status][] as filter[status][0] once it has been through a visit.
 */
const params = computed(() => new URLSearchParams(page.url.split('?')[1] ?? ''));

function isCurrent(view) {
    if (view.when) {
        return params.value.get('filter[when]') === view.when;
    }

    const statuses = [...params.value.entries()]
        .filter(([key]) => key.startsWith('filter[status]'))
        .map(([, value]) => value);

    return !params.value.get('filter[when]') && statuses.length === 1 && statuses[0] === view.status;
}
</script>

<template>
    <Head title="Classes" />

    <AppLayout title="Classes" :breadcrumbs="[{ label: 'Admin', href: '/admin' }, { label: 'Classes' }]">
        <div class="mx-auto max-w-7xl">
            <PageHeader
                title="Classes"
                description="Every live class across every batch. Classes are scheduled and registers marked from each batch's own screen."
            />

            <div class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatTile label="Today" :value="counts.today ?? 0" tone="brand" />
                <StatTile label="Next 7 days" :value="counts.week ?? 0" />
                <StatTile
                    label="Register not marked"
                    :value="counts.unmarked ?? 0"
                    :tone="counts.unmarked ? 'warning' : 'neutral'"
                    hint="Held, with nobody marked present or absent"
                />
            </div>

            <nav class="mb-4 flex flex-wrap gap-2" aria-label="Quick views">
                <Link
                    v-for="view in views"
                    :key="view.label"
                    :href="`/admin/classes?${view.query}`"
                    preserve-scroll
                    class="rounded-full border px-3 py-1.5 text-sm transition"
                    :class="isCurrent(view) ? 'font-semibold' : ''"
                    :style="isCurrent(view)
                        ? { borderColor: 'var(--color-brand-500)', color: 'var(--color-brand-600)', background: 'var(--surface)' }
                        : { borderColor: 'var(--border-subtle)', color: 'var(--text-muted)' }"
                    :aria-current="isCurrent(view) ? 'page' : undefined"
                >
                    {{ view.label }}
                </Link>
            </nav>

            <DataTable
                :table="table"
                search-placeholder="Search by class or batch…"
                empty-title="No classes match"
                empty-description="Try another view, or clear the filters."
                :only="['table', 'counts']"
            >
                <template #cell:scheduled_at="{ row }">
                    <span class="block whitespace-nowrap">{{ row.scheduled_at }}</span>
                    <span class="block text-xs" style="color: var(--text-muted)">{{ row.relative }}</span>
                </template>

                <template #cell:title="{ row }">
                    <span class="font-medium" style="color: var(--text-strong)">{{ row.title }}</span>
                </template>

                <template #cell:batch="{ row }">
                    <Link :href="`/admin/batches/${row.batchId}/run`" class="hover:underline">{{ row.batch }}</Link>
                </template>

                <template #cell:status="{ row }">
                    <span class="inline-flex flex-wrap items-center gap-1.5">
                        <UiBadge v-if="row.isLive" tone="danger" size="sm" dot>live now</UiBadge>
                        <UiBadge v-else :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                    </span>
                </template>

                <template #cell:attendance="{ row }">
                    <span :style="row.unmarked ? { color: 'var(--color-warn-600)', fontWeight: 600 } : {}">{{ row.attendance }}</span>
                </template>

                <template #rowActions="{ row }">
                    <span class="flex items-center justify-end gap-1">
                        <UiButton v-if="row.joinUrl" :href="row.joinUrl" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><Radio class="h-3 w-3" /></template>
                            Join
                        </UiButton>
                        <UiButton v-if="row.recordingUrl" :href="row.recordingUrl" :inertia="false" target="_blank" rel="noopener" variant="ghost" size="xs">
                            <template #leading><PlayCircle class="h-3 w-3" /></template>
                            Recording
                        </UiButton>
                        <UiButton
                            v-if="row.status !== 'cancelled' && row.isPast"
                            :href="`/admin/batches/${row.batchId}/sessions/${row.id}/register`"
                            :variant="row.unmarked ? 'secondary' : 'ghost'"
                            size="xs"
                        >
                            <template #leading><ClipboardCheck class="h-3 w-3" /></template>
                            {{ row.unmarked ? 'Mark register' : 'Register' }}
                        </UiButton>
                    </span>
                </template>

                <template #mobileRow="{ row }">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-semibold">{{ row.title }}</span>
                                <span class="block truncate text-xs" style="color: var(--text-muted)">{{ row.batch }} · {{ row.scheduled_at }}</span>
                            </span>
                            <UiBadge v-if="row.isLive" tone="danger" size="sm" dot>live</UiBadge>
                            <UiBadge v-else :tone="statusTones[row.status] ?? 'neutral'" size="sm" dot>{{ row.status }}</UiBadge>
                        </div>
                        <div class="mt-2 flex items-center justify-between text-xs">
                            <span :style="row.unmarked ? { color: 'var(--color-warn-600)' } : { color: 'var(--text-muted)' }">
                                Attendance: {{ row.attendance }}
                            </span>
                            <Link
                                v-if="row.status !== 'cancelled' && row.isPast"
                                :href="`/admin/batches/${row.batchId}/sessions/${row.id}/register`"
                                class="inline-flex items-center gap-1 font-medium"
                                style="color: var(--color-brand-600)"
                            >
                                Register <ExternalLink class="h-3 w-3" />
                            </Link>
                        </div>
                    </div>
                </template>
            </DataTable>
        </div>
    </AppLayout>
</template>
