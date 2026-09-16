<script setup>
/**
 * The table every dashboard list is built from.
 *
 * State lives in the URL, so a view can be bookmarked and shared, and the
 * export links carry the same query the screen is showing. On phones the rows
 * render as cards instead of a horizontally scrolling grid, because a table
 * that needs sideways scrolling is unusable with one thumb.
 */
import { ref, computed } from 'vue';
import {
    Search, SlidersHorizontal, Download, Columns3, ArrowUp, ArrowDown,
    ChevronsUpDown, X, FileSpreadsheet, FileText, FileDown,
} from 'lucide-vue-next';

import { useTableState } from '@/composables/useTableState';
import UiInput from '@/components/UI/UiInput.vue';
import UiButton from '@/components/UI/UiButton.vue';
import UiBadge from '@/components/UI/UiBadge.vue';
import UiDropdown from '@/components/UI/UiDropdown.vue';
import UiDropdownItem from '@/components/UI/UiDropdownItem.vue';
import UiCheckbox from '@/components/UI/UiCheckbox.vue';
import UiDrawer from '@/components/UI/UiDrawer.vue';
import UiEmptyState from '@/components/UI/UiEmptyState.vue';
import UiSkeleton from '@/components/UI/UiSkeleton.vue';
import UiPagination from '@/components/UI/UiPagination.vue';
import UiSelect from '@/components/UI/UiSelect.vue';
import TableFilters from './TableFilters.vue';

const props = defineProps({
    /** The payload from App\Support\Table\Table::toArray(). */
    table: { type: Object, required: true },
    searchPlaceholder: { type: String, default: 'Search…' },
    emptyTitle: { type: String, default: 'Nothing here yet' },
    emptyDescription: { type: String, default: 'Try clearing the filters, or widen your search.' },
    /** Enable per row checkboxes. Selected ids are exposed via v-model:selected. */
    selectable: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    /** Inertia partial reload key, so only the table prop is refetched. */
    only: { type: Array, default: null },
});

const emit = defineEmits(['update:selected']);

const state = useTableState(props.table, { only: props.only });

const filtersOpen = ref(false);

// Column visibility is a client side preference, so it lives in component
// state rather than the URL. The server always sends every column.
const hiddenColumns = ref(
    new Set(props.table.columns.filter((column) => !column.visible).map((column) => column.key)),
);

const visibleColumns = computed(() =>
    props.table.columns.filter((column) => !hiddenColumns.value.has(column.key)),
);

function toggleColumn(key) {
    const next = new Set(hiddenColumns.value);
    next.has(key) ? next.delete(key) : next.add(key);
    hiddenColumns.value = next;
}

const rows = computed(() => props.table.rows ?? []);
const hasRows = computed(() => rows.value.length > 0);

/* ------------------------------------------------------------------ selection */

const allSelected = computed(
    () => hasRows.value && rows.value.every((row) => props.selected.includes(row[props.rowKey])),
);

const someSelected = computed(
    () => !allSelected.value && rows.value.some((row) => props.selected.includes(row[props.rowKey])),
);

function toggleAll() {
    const ids = rows.value.map((row) => row[props.rowKey]);

    emit(
        'update:selected',
        allSelected.value
            ? props.selected.filter((id) => !ids.includes(id))
            : [...new Set([...props.selected, ...ids])],
    );
}

function toggleRow(id) {
    emit(
        'update:selected',
        props.selected.includes(id)
            ? props.selected.filter((existing) => existing !== id)
            : [...props.selected, id],
    );
}

const exportFormats = [
    { format: 'csv', label: 'CSV', icon: FileDown },
    { format: 'xlsx', label: 'Excel', icon: FileSpreadsheet },
    { format: 'pdf', label: 'PDF', icon: FileText },
];
</script>

<template>
    <div class="space-y-3">
        <!-- ------------------------------------------------------------ toolbar -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="min-w-0 flex-1 sm:max-w-xs">
                <UiInput
                    v-model="state.search.value"
                    type="search"
                    :placeholder="searchPlaceholder"
                    size="sm"
                    aria-label="Search this table"
                >
                    <template #leading><Search class="h-4 w-4" /></template>
                </UiInput>
            </div>

            <div class="flex items-center gap-2">
                <UiButton
                    v-if="table.filters.length"
                    variant="secondary"
                    size="sm"
                    @click="filtersOpen = true"
                >
                    <template #leading><SlidersHorizontal class="h-4 w-4" /></template>
                    <span class="hidden sm:inline">Filters</span>
                    <UiBadge v-if="state.activeFilterCount.value" tone="brand" size="sm">
                        {{ state.activeFilterCount.value }}
                    </UiBadge>
                </UiButton>

                <!-- Column picker, desktop only. -->
                <span class="hidden sm:contents">
                <UiDropdown>
                    <template #trigger>
                        <UiButton variant="secondary" size="sm" icon aria-label="Choose columns">
                            <Columns3 class="h-4 w-4" />
                        </UiButton>
                    </template>

                    <p class="px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide" style="color: var(--text-muted)">
                        Columns
                    </p>
                    <div class="max-h-72 overflow-y-auto scrollbar-thin px-2.5 py-1" @click.stop>
                        <label
                            v-for="column in table.columns"
                            :key="column.key"
                            class="flex cursor-pointer items-center gap-2.5 py-1.5"
                        >
                            <UiCheckbox
                                :model-value="!hiddenColumns.has(column.key)"
                                @update:model-value="toggleColumn(column.key)"
                            />
                            <span class="text-sm" style="color: var(--text-base)">{{ column.label }}</span>
                        </label>
                    </div>
                </UiDropdown>
                </span>

                <UiDropdown v-if="table.exportable">
                    <template #trigger>
                        <UiButton variant="secondary" size="sm">
                            <template #leading><Download class="h-4 w-4" /></template>
                            <span class="hidden sm:inline">Export</span>
                        </UiButton>
                    </template>

                    <p class="px-2.5 py-1.5 text-xs" style="color: var(--text-muted)">
                        Exports every row matching the current filters.
                    </p>
                    <UiDropdownItem
                        v-for="option in exportFormats"
                        :key="option.format"
                        :href="state.exportUrl(option.format)"
                        :inertia="false"
                        as="a"
                    >
                        <component :is="option.icon" class="h-4 w-4" />
                        {{ option.label }}
                    </UiDropdownItem>
                </UiDropdown>

                <slot name="actions" />
            </div>
        </div>

        <!-- Active filter summary, so a filtered view never looks like an empty one. -->
        <div v-if="state.activeFilterCount.value || state.search.value" class="flex items-center gap-2 text-xs">
            <span style="color: var(--text-muted)">
                {{ table.meta.total }} {{ table.meta.total === 1 ? 'result' : 'results' }} after filtering
            </span>
            <button
                type="button"
                class="inline-flex items-center gap-1 rounded-full bg-[var(--surface-sunken)] px-2 py-1 font-medium transition hover:text-[var(--text-strong)]"
                style="color: var(--text-muted)"
                @click="state.clearFilters()"
            >
                <X class="h-3 w-3" /> Clear all
            </button>
        </div>

        <!-- ------------------------------------------------------- desktop table -->
        <div
            class="hidden overflow-hidden rounded-[var(--radius-card)] border bg-[var(--surface)] sm:block"
            style="border-color: var(--border-subtle)"
        >
            <div class="overflow-x-auto scrollbar-thin">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b bg-[var(--surface-sunken)]" style="border-color: var(--border-subtle)">
                            <th v-if="selectable" scope="col" class="w-10 px-4 py-3">
                                <UiCheckbox
                                    :model-value="allSelected"
                                    :indeterminate="someSelected"
                                    @update:model-value="toggleAll"
                                />
                                <span class="sr-only">Select all rows on this page</span>
                            </th>

                            <th
                                v-for="column in visibleColumns"
                                :key="column.key"
                                scope="col"
                                :style="column.width ? `width:${column.width}` : ''"
                                :class="[
                                    'px-4 py-3 text-xs font-semibold uppercase tracking-wide whitespace-nowrap',
                                    column.numeric ? 'text-right' : 'text-left',
                                ]"
                            >
                                <button
                                    v-if="column.sortable"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 transition hover:text-[var(--text-strong)]"
                                    style="color: var(--text-muted)"
                                    :aria-label="`Sort by ${column.label}`"
                                    @click="state.toggleSort(column.key)"
                                >
                                    {{ column.label }}
                                    <ArrowUp v-if="state.sortDirection(column.key) === 'asc'" class="h-3 w-3 text-brand-600 dark:text-brand-400" />
                                    <ArrowDown v-else-if="state.sortDirection(column.key) === 'desc'" class="h-3 w-3 text-brand-600 dark:text-brand-400" />
                                    <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                </button>
                                <span v-else style="color: var(--text-muted)">{{ column.label }}</span>
                            </th>

                            <th v-if="$slots.rowActions" scope="col" class="w-12 px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template v-if="state.loading.value && !hasRows">
                            <tr v-for="n in 5" :key="`skeleton-${n}`" class="border-b" style="border-color: var(--border-subtle)">
                                <td v-if="selectable" class="px-4 py-3.5"><UiSkeleton class="h-4 w-4" /></td>
                                <td v-for="column in visibleColumns" :key="column.key" class="px-4 py-3.5">
                                    <UiSkeleton class="h-4 w-24" />
                                </td>
                                <td v-if="$slots.rowActions" class="px-4 py-3.5" />
                            </tr>
                        </template>

                        <tr
                            v-for="row in rows"
                            v-else
                            :key="row[rowKey]"
                            :class="[
                                'border-b transition-colors last:border-0 hover:bg-[var(--surface-sunken)]',
                                selected.includes(row[rowKey]) && 'bg-brand-50/60 dark:bg-brand-950/30',
                            ]"
                            style="border-color: var(--border-subtle)"
                        >
                            <td v-if="selectable" class="px-4 py-3.5">
                                <UiCheckbox
                                    :model-value="selected.includes(row[rowKey])"
                                    @update:model-value="toggleRow(row[rowKey])"
                                />
                            </td>

                            <td
                                v-for="column in visibleColumns"
                                :key="column.key"
                                :class="['px-4 py-3.5', column.numeric && 'text-right tnum']"
                                style="color: var(--text-base)"
                            >
                                <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </td>

                            <td v-if="$slots.rowActions" class="px-4 py-3.5 text-right">
                                <slot name="rowActions" :row="row" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <UiEmptyState
                v-if="!hasRows && !state.loading.value"
                :title="emptyTitle"
                :description="emptyDescription"
            >
                <template v-if="state.activeFilterCount.value || state.search.value" #action>
                    <UiButton variant="secondary" size="sm" @click="state.clearFilters()">Clear filters</UiButton>
                </template>
            </UiEmptyState>

            <div class="px-4">
                <UiPagination :meta="table.meta" :links="table.links" />
            </div>
        </div>

        <!-- ---------------------------------------------------------- phone cards -->
        <div class="space-y-2.5 sm:hidden">
            <template v-if="state.loading.value && !hasRows">
                <div
                    v-for="n in 4"
                    :key="`m-skeleton-${n}`"
                    class="space-y-2 rounded-[var(--radius-card)] border bg-[var(--surface)] p-4"
                    style="border-color: var(--border-subtle)"
                >
                    <UiSkeleton class="h-4 w-1/2" />
                    <UiSkeleton class="h-3 w-3/4" />
                </div>
            </template>

            <template v-else-if="hasRows">
                <div
                    v-for="row in rows"
                    :key="`m-${row[rowKey]}`"
                    class="rounded-[var(--radius-card)] border bg-[var(--surface)] p-4 shadow-[var(--shadow-card)]"
                    style="border-color: var(--border-subtle)"
                >
                    <slot name="mobileRow" :row="row">
                        <dl class="space-y-1.5">
                            <div
                                v-for="column in visibleColumns"
                                :key="column.key"
                                class="flex items-baseline justify-between gap-3"
                            >
                                <dt class="text-xs shrink-0" style="color: var(--text-muted)">{{ column.label }}</dt>
                                <dd
                                    class="min-w-0 text-right text-sm"
                                    :class="column.numeric && 'tnum'"
                                    style="color: var(--text-strong)"
                                >
                                    <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                        {{ row[column.key] ?? '—' }}
                                    </slot>
                                </dd>
                            </div>
                        </dl>

                        <div v-if="$slots.rowActions" class="mt-3 flex justify-end border-t pt-3" style="border-color: var(--border-subtle)">
                            <slot name="rowActions" :row="row" />
                        </div>
                    </slot>
                </div>
            </template>

            <UiEmptyState
                v-else
                class="rounded-[var(--radius-card)] border bg-[var(--surface)]"
                :title="emptyTitle"
                :description="emptyDescription"
            />

            <UiPagination :meta="table.meta" :links="table.links" />
        </div>

        <!-- Per page control sits below, where it does not compete with search. -->
        <div v-if="table.meta.total > 10" class="flex items-center justify-end gap-2 text-xs">
            <span style="color: var(--text-muted)">Rows per page</span>
            <div class="w-20">
                <UiSelect
                    v-model="state.perPage.value"
                    size="sm"
                    :options="table.per_page_options.map((n) => ({ value: n, label: String(n) }))"
                    aria-label="Rows per page"
                />
            </div>
        </div>

        <!-- ------------------------------------------------------- filter drawer -->
        <UiDrawer :open="filtersOpen" title="Filters" @close="filtersOpen = false">
            <TableFilters
                :filters="table.filters"
                :model-value="state.filters.value"
                @change="state.setFilter"
            />

            <template #footer>
                <div class="flex gap-2">
                    <UiButton variant="secondary" block @click="state.clearFilters(); filtersOpen = false">
                        Clear
                    </UiButton>
                    <UiButton block @click="filtersOpen = false">Done</UiButton>
                </div>
            </template>
        </UiDrawer>
    </div>
</template>
