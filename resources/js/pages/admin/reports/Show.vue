<script setup>
/**
 * One report.
 *
 * Headline figures, the chart or two that make the shape visible, and the rows
 * behind them. The rows are not an appendix: a figure nobody can drill into is
 * a figure nobody can check, and they are what the downloads are made from, so
 * a spreadsheet can never disagree with the screen.
 */
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Download, Printer, CalendarClock, ArrowLeft, Mail, Trash2 } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import PageHeader from '@/components/Admin/PageHeader.vue';
import StatTile from '@/components/Admin/StatTile.vue';
import UiCard from '@/components/UI/UiCard.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiInput from '@/components/UI/UiInput.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import UiModal from '@/components/UI/UiModal.vue';
import UiDateInput from '@/components/UI/UiDateInput.vue';
import UiFormField from '@/components/UI/UiFormField.vue';
import UiRepeater from '@/components/UI/UiRepeater.vue';
import UiChartFrame from '@/components/Charts/UiChartFrame.vue';
import UiLineChart from '@/components/Charts/UiLineChart.vue';
import UiBarChart from '@/components/Charts/UiBarChart.vue';
import UiRankedBars from '@/components/Charts/UiRankedBars.vue';
import { withColors, rupeeTick, plainTick } from '@/support/charts';

const props = defineProps({
    report: { type: Object, required: true },
    library: { type: Array, default: () => [] },
    schedules: { type: Array, default: () => [] },
});

/* ------------------------------------------------------------- the window */

const from = ref(props.report.window.from);
const to = ref(props.report.window.to);
const extra = ref({ ...(props.report.window.filters ?? {}) });

function apply() {
    router.get(
        `/admin/reports/${props.report.key}`,
        { from: from.value, to: to.value, filters: extra.value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** Ranges people actually ask for, rather than a date picker and good luck. */
const presets = [
    { label: 'Last 30 days', days: 30 },
    { label: 'Last 3 months', days: 90 },
    { label: 'Last 12 months', days: 365 },
];

function preset(days) {
    const end = new Date();
    const start = new Date(end.getTime() - days * 86400000);

    from.value = start.toISOString().slice(0, 10);
    to.value = end.toISOString().slice(0, 10);
    apply();
}

const exportUrl = computed(() => (format) => {
    const query = new URLSearchParams({ from: from.value, to: to.value, export: format });

    Object.entries(extra.value).forEach(([key, value]) => {
        if (value) query.append(`filters[${key}]`, value);
    });

    return `/admin/reports/${props.report.key}?${query.toString()}`;
});

/* -------------------------------------------------------------- charts */

function seriesFor(chart) {
    return withColors(chart.series, { ramp: Boolean(chart.ramp) });
}

function tickFor(chart) {
    return chart.money ? rupeeTick : plainTick;
}

/** The table behind a chart: categories down the side, one column per series. */
function chartTable(chart) {
    return {
        columns: ['', ...chart.series.map((one) => one.label)],
        rows: chart.categories.map((category, index) => [
            category,
            ...chart.series.map((one) => one.display?.[index] ?? plainTick(one.values[index])),
        ]),
    };
}

function rankedRows(chart) {
    return chart.categories.map((label, index) => ({
        label,
        value: chart.series[0].values[index],
        display: chart.series[0].display?.[index] ?? plainTick(chart.series[0].values[index]),
    }));
}

const isEmpty = (chart) => chart.series.every((one) => one.values.every((value) => !Number(value)));

/* ------------------------------------------------------------ scheduling */

const scheduling = ref(false);

const scheduleForm = useForm({
    cadence: 'monthly',
    day: 1,
    hour: 7,
    recipients: [],
    format: 'pdf',
    filters: {},
});

function schedule() {
    scheduleForm.filters = { ...extra.value };

    scheduleForm.post(`/admin/reports/${props.report.key}/schedule`, {
        preserveScroll: true,
        onSuccess: () => {
            scheduling.value = false;
            scheduleForm.reset();
        },
    });
}

function stop(one) {
    if (confirm('Stop sending this?')) {
        router.delete(`/admin/reports/schedules/${one.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="report.title" />

    <AppLayout
        :title="report.title"
        :breadcrumbs="[
            { label: 'Dashboard', href: '/admin' },
            { label: 'Reports', href: '/admin/reports' },
            { label: report.title },
        ]"
    >
        <div class="mx-auto max-w-6xl space-y-5">
            <PageHeader :title="report.title" :description="report.description">
                <template #actions>
                    <UiButton href="/admin/reports" variant="ghost" size="sm" class="no-print">
                        <template #leading><ArrowLeft class="h-3.5 w-3.5" /></template>
                        All reports
                    </UiButton>
                    <UiButton variant="ghost" size="sm" class="no-print" @click="scheduling = true">
                        <template #leading><CalendarClock class="h-3.5 w-3.5" /></template>
                        Email it
                    </UiButton>
                    <UiButton variant="secondary" size="sm" class="no-print" @click="print()">
                        <template #leading><Printer class="h-3.5 w-3.5" /></template>
                        Print
                    </UiButton>
                    <UiButton :href="exportUrl('csv')" :inertia="false" variant="secondary" size="sm" class="no-print">
                        <template #leading><Download class="h-3.5 w-3.5" /></template>
                        CSV
                    </UiButton>
                    <UiButton :href="exportUrl('xlsx')" :inertia="false" variant="secondary" size="sm" class="no-print">
                        Excel
                    </UiButton>
                    <UiButton :href="exportUrl('pdf')" :inertia="false" variant="secondary" size="sm" class="no-print">
                        PDF
                    </UiButton>
                </template>
            </PageHeader>

            <!-- ----------------------------------------------- the window -->
            <UiCard padding="p-4" class="no-print">
                <div class="flex flex-wrap items-end gap-3">
                    <UiFormField label="From" class="w-40">
                        <UiDateInput v-model="from" @change="apply" />
                    </UiFormField>

                    <UiFormField label="To" class="w-40">
                        <UiDateInput v-model="to" @change="apply" />
                    </UiFormField>

                    <UiFormField
                        v-for="control in report.controls"
                        :key="control.key"
                        :label="control.label"
                        class="w-52"
                    >
                        <UiSelect v-model="extra[control.key]" @change="apply">
                            <option value="">Everything</option>
                            <option v-for="option in control.options" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </UiSelect>
                    </UiFormField>

                    <div class="flex flex-wrap gap-1.5">
                        <UiButton
                            v-for="range in presets"
                            :key="range.label"
                            variant="ghost"
                            size="xs"
                            @click="preset(range.days)"
                        >
                            {{ range.label }}
                        </UiButton>
                    </div>
                </div>
            </UiCard>

            <p class="px-1 text-xs" style="color: var(--text-muted)">
                {{ report.window.label }} · {{ report.rowCount }} rows
            </p>

            <!-- --------------------------------------------- the headlines -->
            <div v-if="report.summary.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    v-for="figure in report.summary"
                    :key="figure.label"
                    :label="figure.label"
                    :value="figure.value"
                    :hint="figure.hint"
                    :tone="figure.tone ?? 'neutral'"
                />
            </div>

            <!-- ------------------------------------------------- the charts -->
            <UiCard v-for="chart in report.charts" :key="chart.id">
                <UiChartFrame
                    :title="chart.title"
                    :subtitle="chart.subtitle"
                    :series="chart.ramp ? [] : seriesFor(chart)"
                    :key-shape="chart.kind === 'line' ? 'line' : 'rect'"
                    :columns="chartTable(chart).columns"
                    :rows="chartTable(chart).rows"
                    :empty="isEmpty(chart)"
                >
                    <UiLineChart
                        v-if="chart.kind === 'line'"
                        :categories="chart.categories"
                        :series="seriesFor(chart)"
                        :tick="tickFor(chart)"
                    />

                    <UiRankedBars
                        v-else-if="chart.horizontal || chart.ramp"
                        :rows="rankedRows(chart)"
                    />

                    <UiBarChart
                        v-else
                        :categories="chart.categories"
                        :series="seriesFor(chart)"
                        :stacked="chart.kind === 'stacked'"
                        :tick="tickFor(chart)"
                    />
                </UiChartFrame>
            </UiCard>

            <!-- --------------------------------------------------- the rows -->
            <UiCard padding="p-0">
                <template #header>
                    <h2 class="text-base font-semibold">The rows behind it</h2>
                </template>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b" style="border-color: var(--border-subtle)">
                                <th
                                    v-for="(column, index) in report.columns"
                                    :key="column.key"
                                    class="whitespace-nowrap px-3 py-2 text-xs font-medium"
                                    :class="column.numeric ? 'text-right' : 'text-left'"
                                    style="color: var(--text-muted)"
                                    scope="col"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, index) in report.rows"
                                :key="index"
                                class="border-b last:border-0"
                                style="border-color: var(--border-subtle)"
                                :style="row.atRisk || row.overdue || row.low || row.withdrawn
                                    ? { background: 'var(--surface-sunken)' }
                                    : undefined"
                            >
                                <td
                                    v-for="column in report.columns"
                                    :key="column.key"
                                    class="px-3 py-2"
                                    :class="column.numeric ? 'text-right tnum' : 'text-left'"
                                >
                                    {{ row[column.key] ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="!report.rows.length" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted)">
                    Nothing in this period.
                </p>
            </UiCard>

            <!-- ------------------------------------------ what is scheduled -->
            <UiCard v-if="schedules.length" class="no-print">
                <template #header>
                    <h2 class="flex items-center gap-2 text-base font-semibold">
                        <Mail class="h-4 w-4" style="color: var(--text-muted)" />
                        This report, by email
                    </h2>
                </template>

                <ul class="divide-y" style="border-color: var(--border-subtle)">
                    <li
                        v-for="one in schedules"
                        :key="one.id"
                        class="flex flex-wrap items-center gap-3 py-2.5 first:pt-0 last:pb-0"
                    >
                        <span class="min-w-0 flex-1 text-sm">
                            {{ one.cadence }} at {{ one.hour }}
                            <span class="block text-xs" style="color: var(--text-muted)">
                                {{ one.recipients.join(', ') }}
                            </span>
                        </span>
                        <UiBadge size="sm">{{ one.format }}</UiBadge>
                        <UiButton variant="ghost" size="xs" aria-label="Stop" @click="stop(one)">
                            <Trash2 class="h-3.5 w-3.5" style="color: var(--color-danger-500)" />
                        </UiButton>
                    </li>
                </ul>
            </UiCard>

            <!-- ------------------------------------------- other reports -->
            <nav class="no-print flex flex-wrap gap-2 pt-2">
                <Link
                    v-for="one in library"
                    :key="one.key"
                    :href="`/admin/reports/${one.key}`"
                    class="rounded-full border px-3 py-1 text-xs transition hover:bg-[var(--surface-sunken)]"
                    :style="{
                        borderColor: one.key === report.key ? 'var(--color-brand-500)' : 'var(--border-subtle)',
                        color: one.key === report.key ? 'var(--color-brand-600)' : 'var(--text-muted)',
                    }"
                >
                    {{ one.title }}
                </Link>
            </nav>
        </div>

        <!-- ------------------------------------------------- scheduling -->
        <UiModal :open="scheduling" title="Send this report by email" @close="scheduling = false">
            <form class="space-y-4" @submit.prevent="schedule">
                <p class="text-sm" style="color: var(--text-muted)">
                    It is generated fresh each time from the same filters you have set here, so what arrives is
                    current rather than a copy of today.
                </p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <UiFormField label="How often" :error="scheduleForm.errors.cadence">
                        <UiSelect v-model="scheduleForm.cadence">
                            <option value="daily">Every day</option>
                            <option value="weekly">Every week</option>
                            <option value="monthly">Every month</option>
                        </UiSelect>
                    </UiFormField>

                    <UiFormField
                        v-if="scheduleForm.cadence !== 'daily'"
                        :label="scheduleForm.cadence === 'weekly' ? 'Day of the week' : 'Day of the month'"
                        :error="scheduleForm.errors.day"
                    >
                        <UiInput
                            v-model.number="scheduleForm.day"
                            type="number"
                            min="1"
                            :max="scheduleForm.cadence === 'weekly' ? 7 : 28"
                        />
                    </UiFormField>

                    <UiFormField label="Hour" :error="scheduleForm.errors.hour">
                        <UiInput v-model.number="scheduleForm.hour" type="number" min="0" max="23" />
                    </UiFormField>
                </div>

                <UiFormField
                    label="Send to"
                    hint="Addresses, not accounts — an accountant does not need a login to read the numbers."
                    :error="scheduleForm.errors.recipients"
                >
                    <UiRepeater v-model="scheduleForm.recipients" placeholder="name@example.com, then Enter" />
                </UiFormField>

                <UiFormField label="As" :error="scheduleForm.errors.format">
                    <UiSelect v-model="scheduleForm.format">
                        <option value="pdf">PDF</option>
                        <option value="xlsx">Excel</option>
                        <option value="csv">CSV</option>
                    </UiSelect>
                </UiFormField>
            </form>

            <template #footer>
                <UiButton variant="ghost" @click="scheduling = false">Cancel</UiButton>
                <UiButton
                    :loading="scheduleForm.processing"
                    :disabled="!scheduleForm.recipients.length"
                    @click="schedule"
                >
                    Schedule it
                </UiButton>
            </template>
        </UiModal>
    </AppLayout>
</template>
