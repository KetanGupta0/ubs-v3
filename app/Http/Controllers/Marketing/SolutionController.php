<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The solutions catalogue.
 *
 * With no live client work to show, this is the portfolio, so it is built to be
 * browsed rather than skimmed: searchable, filterable by four facets, and with
 * a full page behind every card.
 *
 * Filtering happens on the server and lives in the query string, so a filtered
 * view can be sent to a colleague and arrives showing the same thing.
 */
class SolutionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $solutions = Solution::query()
            ->published()
            ->with('category')
            ->when($filters['q'], fn (Builder $q, string $term) => $this->search($q, $term))
            ->when($filters['category'], fn (Builder $q, string $slug) => $q->whereHas(
                'category',
                fn (Builder $c) => $c->where('slug', $slug),
            ))
            ->when($filters['industry'], fn (Builder $q, string $value) => $this->whereJsonContainsish($q, 'industries', $value))
            ->when($filters['platform'], fn (Builder $q, string $value) => $this->whereJsonContainsish($q, 'platforms', $value))
            ->when($filters['tech'], fn (Builder $q, string $value) => $this->whereJsonContainsish($q, 'tech_stack', $value))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('marketing/solutions/Index', [
            'solutions' => $solutions->map->toCardArray()->values(),
            'categories' => SolutionCategory::query()
                ->withCount(['solutions' => fn (Builder $q) => $q->where('is_published', true)])
                ->orderBy('sort_order')
                ->get()
                ->map(fn (SolutionCategory $category) => [
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'icon' => $category->icon,
                    'tagline' => $category->tagline,
                    'count' => $category->solutions_count,
                ]),
            'facets' => $this->facets(),
            'filters' => $filters,
            'totalCount' => Solution::query()->published()->count(),
            'seo' => Seo::for(
                'Software we build — solutions catalogue',
                'Browse the software we build, from enterprise resource planning and marketplaces to hospital, school and logistics systems. Filter by industry, platform and technology.',
                '/solutions',
                Seo::breadcrumbs([
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'Solutions', 'href' => '/solutions'],
                ]),
            ),
        ]);
    }

    public function show(Solution $solution): Response
    {
        abort_unless($solution->is_published, 404);

        $solution->load('category');

        return Inertia::render('marketing/solutions/Show', [
            'solution' => [
                ...$solution->toCardArray(),
                'description' => $solution->description,
                'features' => $solution->features ?? [],
                'modules' => $solution->modules ?? [],
                'outcomes' => $solution->outcomes ?? [],
                'integrations' => $solution->integrations ?? [],
                'techStack' => $solution->tech_stack ?? [],
                'platforms' => $solution->platforms ?? [],
                'industries' => $solution->industries ?? [],
                'needsApiKeys' => $solution->needs_api_keys,
                'categoryTagline' => $solution->category?->tagline,
            ],
            'related' => Solution::query()
                ->published()
                ->where('solution_category_id', $solution->solution_category_id)
                ->whereKeyNot($solution->getKey())
                ->with('category')
                ->orderBy('sort_order')
                ->limit(3)
                ->get()
                ->map->toCardArray()
                ->values(),
            'seo' => Seo::for(
                $solution->seo['title'] ?? $solution->title,
                $solution->seo['description'] ?? $solution->summary,
                "/solutions/{$solution->slug}",
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Service',
                    'name' => $solution->title,
                    'description' => $solution->summary,
                    'provider' => ['@type' => 'Organization', 'name' => 'Unboundbyte Solutions Private Limited'],
                    'areaServed' => 'IN',
                    'serviceType' => $solution->category?->name,
                ],
            ),
        ]);
    }

    /** @return array<string, string|null> */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q')) ?: null,
            'category' => $request->query('category') ?: null,
            'industry' => $request->query('industry') ?: null,
            'platform' => $request->query('platform') ?: null,
            'tech' => $request->query('tech') ?: null,
        ];
    }

    protected function search(Builder $query, string $term): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        return $query->where(function (Builder $q) use ($escaped) {
            foreach (['title', 'tagline', 'summary'] as $column) {
                $q->orWhere($column, 'like', "%{$escaped}%");
            }

            // Also match the facet lists, so searching "pharmacy" or "Laravel"
            // finds the products that mention them.
            foreach (['industries', 'tech_stack', 'platforms'] as $column) {
                $q->orWhere($column, 'like', "%{$escaped}%");
            }
        });
    }

    /**
     * Match a value inside a JSON array column.
     *
     * A LIKE against the encoded JSON rather than whereJsonContains, because the
     * value is compared case insensitively and the quoting keeps it anchored to
     * a whole element rather than matching a substring of a longer one.
     */
    protected function whereJsonContainsish(Builder $query, string $column, string $value): Builder
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);

        return $query->where($column, 'like', '%"'.$escaped.'"%');
    }

    /**
     * The filter options, built from what the published rows actually contain.
     *
     * Derived rather than hard coded, so adding a solution with a new industry
     * makes that industry filterable without a second edit somewhere else.
     *
     * @return array<string, array<int, string>>
     */
    protected function facets(): array
    {
        $rows = Solution::query()
            ->published()
            ->get(['industries', 'platforms', 'tech_stack']);

        /*
         * Ordered by how many products carry each value, not alphabetically.
         * A facet list is a browsing aid, so the values that actually narrow
         * the catalogue belong at the top; the long tail is still reachable
         * through search and through the "show all" toggle.
         */
        $collect = fn (string $key) => $rows
            ->flatMap(fn (Solution $solution) => $solution->{$key} ?? [])
            ->countBy()
            ->sortByDesc(fn (int $count, string $value) => [$count, -ord($value)])
            ->keys()
            ->values()
            ->all();

        return [
            'industries' => $collect('industries'),
            'platforms' => $collect('platforms'),
            'tech' => $collect('tech_stack'),
        ];
    }
}
