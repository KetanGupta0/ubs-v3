/**
 * Keeps a table's search, sort, filter and paging state in the URL.
 *
 * The URL is the single source of truth. That means a filtered view can be
 * bookmarked, shared with a colleague, and survives the back button, and it is
 * also what makes exports honest: the download link carries the same query
 * string the screen is showing.
 */
import { ref, computed, watch } from 'vue';
import { router } from '@inertiajs/vue3';

export function useTableState(initial, options = {}) {
    const only = options.only ?? null;
    const debounceMs = options.debounce ?? 300;

    const search = ref(initial.state.q ?? '');
    const sort = ref(initial.state.sort ?? '');
    const filters = ref({ ...(initial.state.filter ?? {}) });
    const perPage = ref(initial.state.per_page ?? 25);
    const loading = ref(false);

    let timer = null;

    function queryObject(overrides = {}) {
        const query = {
            q: search.value || undefined,
            sort: sort.value || undefined,
            per_page: perPage.value,
            filter: Object.keys(filters.value).length ? filters.value : undefined,
            ...overrides,
        };

        // Drop empty filter entries so the URL stays readable.
        if (query.filter) {
            const cleaned = Object.fromEntries(
                Object.entries(query.filter).filter(([, value]) => {
                    if (value === null || value === '' || value === undefined) return false;
                    if (Array.isArray(value)) return value.length > 0;
                    if (typeof value === 'object') {
                        return Object.values(value).some((v) => v !== null && v !== '');
                    }
                    return true;
                }),
            );

            query.filter = Object.keys(cleaned).length ? cleaned : undefined;
        }

        return query;
    }

    function reload(overrides = {}) {
        router.get(window.location.pathname, queryObject(overrides), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only,
            onStart: () => { loading.value = true; },
            onFinish: () => { loading.value = false; },
        });
    }

    function reloadDebounced() {
        clearTimeout(timer);
        timer = setTimeout(() => reload({ page: 1 }), debounceMs);
    }

    /** Toggle a column between ascending, descending and back. */
    function toggleSort(key) {
        if (sort.value === key) {
            sort.value = `-${key}`;
        } else if (sort.value === `-${key}`) {
            sort.value = key;
        } else {
            sort.value = key;
        }

        reload({ page: 1 });
    }

    function sortDirection(key) {
        if (sort.value === key) return 'asc';
        if (sort.value === `-${key}`) return 'desc';
        return null;
    }

    function setFilter(key, value) {
        filters.value = { ...filters.value, [key]: value };
        reload({ page: 1 });
    }

    function clearFilters() {
        filters.value = {};
        search.value = '';
        reload({ page: 1 });
    }

    const activeFilterCount = computed(
        () =>
            Object.values(filters.value).filter((value) => {
                if (value === null || value === '' || value === undefined) return false;
                if (Array.isArray(value)) return value.length > 0;
                if (typeof value === 'object') {
                    return Object.values(value).some((v) => v !== null && v !== '');
                }
                return true;
            }).length,
    );

    /** Href for a download that mirrors exactly what is on screen. */
    function exportUrl(format) {
        const params = new URLSearchParams();
        const query = queryObject({ export: format, page: undefined, per_page: undefined });

        const append = (key, value) => {
            if (value === undefined || value === null || value === '') return;
            if (Array.isArray(value)) {
                value.forEach((v) => params.append(`${key}[]`, v));
            } else if (typeof value === 'object') {
                Object.entries(value).forEach(([k, v]) => append(`${key}[${k}]`, v));
            } else {
                params.append(key, value);
            }
        };

        Object.entries(query).forEach(([key, value]) => append(key, value));

        return `${window.location.pathname}?${params.toString()}`;
    }

    watch(search, reloadDebounced);
    watch(perPage, () => reload({ page: 1 }));

    return {
        search,
        sort,
        filters,
        perPage,
        loading,
        toggleSort,
        sortDirection,
        setFilter,
        clearFilters,
        activeFilterCount,
        exportUrl,
        reload,
    };
}
