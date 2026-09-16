<?php

namespace App\Support\Table;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server driven table: search, filter, sort, paginate and export.
 *
 * Every dashboard list in the application is built on this one class, so the
 * behaviour a user learns on one screen holds on all of them, and an export
 * always reflects exactly the rows the filters selected rather than the page
 * that happened to be on screen.
 *
 * Nothing from the request ever reaches SQL directly. Sort columns are matched
 * against a whitelist and filters resolve through their own declared column, so
 * a hand edited query string cannot widen what it can read.
 */
class Table
{
    /** @var array<int, Column> */
    protected array $columns = [];

    /** @var array<int, Filter> */
    protected array $filters = [];

    /** @var array<int, string> */
    protected array $searchable = [];

    /** @var array<int, string> */
    protected array $sortable = [];

    protected string $defaultSort = '-id';

    protected int $perPage = 25;

    /** @var array<int, int> */
    protected array $perPageOptions = [10, 25, 50, 100];

    protected ?Closure $transformer = null;

    protected string $exportName = 'export';

    protected int $maxExportRows = 20000;

    final public function __construct(protected Builder $query) {}

    public static function for(Builder $query): static
    {
        return new static($query);
    }

    /** @param  array<int, string>  $fields */
    public function searchable(array $fields): static
    {
        $this->searchable = $fields;

        return $this;
    }

    /** @param  array<int, string>  $fields */
    public function sortable(array $fields): static
    {
        $this->sortable = $fields;

        return $this;
    }

    /** @param  array<int, Column>  $columns */
    public function columns(array $columns): static
    {
        $this->columns = $columns;

        // Columns declared sortable are sortable, without repeating the list.
        $declared = collect($columns)
            ->filter(fn (Column $column) => $column->isSortable())
            ->map(fn (Column $column) => $column->key())
            ->all();

        $this->sortable = array_values(array_unique([...$this->sortable, ...$declared]));

        return $this;
    }

    /** @param  array<int, Filter>  $filters */
    public function filters(array $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    /** Signed sort key, for example '-created_at' for newest first. */
    public function defaultSort(string $sort): static
    {
        $this->defaultSort = $sort;

        return $this;
    }

    public function perPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /** Shape each row before it reaches the client. */
    public function transform(Closure $callback): static
    {
        $this->transformer = $callback;

        return $this;
    }

    /** Base filename used for downloads, without extension. */
    public function exportName(string $name): static
    {
        $this->exportName = $name;

        return $this;
    }

    /**
     * Build the payload for an Inertia page.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->paginate($request);

        return [
            'rows' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            'links' => $paginator->linkCollection()->toArray(),
            'columns' => collect($this->columns)->map->toArray()->all(),
            'filters' => collect($this->filters)->map->toArray()->all(),
            'state' => [
                'q' => (string) $request->query('q', ''),
                'sort' => $this->resolveSort($request),
                'filter' => $this->resolveFilterValues($request),
                'per_page' => $this->resolvePerPage($request),
            ],
            'per_page_options' => $this->perPageOptions,
            'exportable' => collect($this->columns)->contains->isExportable(),
        ];
    }

    public function paginate(Request $request): LengthAwarePaginator
    {
        $paginator = $this->buildQuery($request)
            ->paginate($this->resolvePerPage($request))
            ->withQueryString();

        if ($this->transformer) {
            $paginator->through($this->transformer);
        }

        return $paginator;
    }

    /**
     * Return a download when the request asks for one, otherwise null so the
     * caller falls through to rendering the page.
     */
    public function exportResponse(Request $request): ?Response
    {
        $format = $request->query('export');

        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            return null;
        }

        $rows = $this->buildQuery($request)->limit($this->maxExportRows)->get();

        if ($this->transformer) {
            $rows = $rows->map($this->transformer);
        }

        $columns = collect($this->columns)->filter->isExportable()->values();

        return (new TableExport($this->exportName, $columns, $rows))->download($format);
    }

    protected function buildQuery(Request $request): Builder
    {
        $query = clone $this->query;

        $this->applySearch($query, (string) $request->query('q', ''));
        $this->applyFilters($query, $request);
        $this->applySort($query, $this->resolveSort($request));

        return $query;
    }

    protected function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '' || $this->searchable === []) {
            return;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        $query->where(function (Builder $q) use ($escaped) {
            foreach ($this->searchable as $field) {
                // Dotted fields address a relation, for example 'client.name'.
                if (str_contains($field, '.')) {
                    [$relation, $column] = explode('.', $field, 2);
                    $q->orWhereHas($relation, fn (Builder $r) => $r->where($column, 'like', "%{$escaped}%"));

                    continue;
                }

                $q->orWhere($field, 'like', "%{$escaped}%");
            }
        });
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $values = $this->resolveFilterValues($request);

        foreach ($this->filters as $filter) {
            $filter->apply($query, $values[$filter->key()] ?? null);
        }
    }

    protected function applySort(Builder $query, string $sort): void
    {
        $descending = str_starts_with($sort, '-');
        $column = ltrim($sort, '-');

        // Ignore anything not explicitly offered as sortable.
        if (! in_array($column, $this->sortable, true)) {
            $fallback = ltrim($this->defaultSort, '-');
            $column = in_array($fallback, $this->sortable, true) ? $fallback : $query->getModel()->getKeyName();
            $descending = str_starts_with($this->defaultSort, '-');
        }

        $query->orderBy($column, $descending ? 'desc' : 'asc');

        // Keep pagination stable when the sort column has duplicate values.
        $key = $query->getModel()->getQualifiedKeyName();

        if ($column !== $query->getModel()->getKeyName()) {
            $query->orderBy($key, 'desc');
        }
    }

    protected function resolveSort(Request $request): string
    {
        $sort = (string) $request->query('sort', '');

        return $sort !== '' ? $sort : $this->defaultSort;
    }

    /** @return array<string, mixed> */
    protected function resolveFilterValues(Request $request): array
    {
        $raw = $request->query('filter', []);

        if (! is_array($raw)) {
            return [];
        }

        $allowed = collect($this->filters)->map->key()->all();

        return collect($raw)->only($allowed)->all();
    }

    protected function resolvePerPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', $this->perPage);

        return in_array($requested, $this->perPageOptions, true) ? $requested : $this->perPage;
    }
}
