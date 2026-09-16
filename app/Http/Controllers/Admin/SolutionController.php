<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The solutions catalogue, from the inside.
 *
 * This is what the public catalogue renders, so publishing here changes the
 * site immediately. Unpublishing is offered alongside deleting, because taking
 * something off the site is usually what an administrator means.
 */
class SolutionController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/catalogue/solutions/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => Solution::query()->count(),
                'published' => Solution::query()->published()->count(),
                'featured' => Solution::query()->where('is_featured', true)->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/catalogue/solutions/Form', [
            'solution' => null,
            'categories' => $this->categoryOptions(),
            'accents' => $this->accents(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validated($request);

        $solution = Solution::query()->create($validated);
        $auditor->created($solution);

        return redirect()
            ->route('admin.solutions.edit', $solution)
            ->with('success', 'Solution created.');
    }

    public function edit(Solution $solution): Response
    {
        return Inertia::render('admin/catalogue/solutions/Form', [
            'solution' => [
                'id' => $solution->id,
                'title' => $solution->title,
                'slug' => $solution->slug,
                'solution_category_id' => $solution->solution_category_id,
                'tagline' => $solution->tagline,
                'summary' => $solution->summary,
                'description' => $solution->description,
                'industries' => $solution->industries ?? [],
                'platforms' => $solution->platforms ?? [],
                'tech_stack' => $solution->tech_stack ?? [],
                'modules' => $solution->modules ?? [],
                'outcomes' => $solution->outcomes ?? [],
                'integrations' => $solution->integrations ?? [],
                'features' => $solution->features ?? [],
                'price_band_min' => $solution->price_band_min,
                'price_band_max' => $solution->price_band_max,
                'timeline_weeks_min' => $solution->timeline_weeks_min,
                'timeline_weeks_max' => $solution->timeline_weeks_max,
                'accent' => $solution->accent,
                'needs_api_keys' => $solution->needs_api_keys,
                'is_featured' => $solution->is_featured,
                'is_published' => $solution->is_published,
                'sort_order' => $solution->sort_order,
                'publicUrl' => "/solutions/{$solution->slug}",
            ],
            'categories' => $this->categoryOptions(),
            'accents' => $this->accents(),
        ]);
    }

    public function update(Request $request, Solution $solution, Auditor $auditor): RedirectResponse
    {
        $solution->fill($this->validated($request, $solution));
        $auditor->updated($solution);
        $solution->save();

        return back()->with('success', 'Solution saved.');
    }

    /** Publish or unpublish without opening the form. */
    public function togglePublished(Solution $solution, Auditor $auditor): RedirectResponse
    {
        $solution->is_published = ! $solution->is_published;
        $auditor->updated($solution);
        $solution->save();

        return back()->with('success', $solution->is_published
            ? 'Now live on the site.'
            : 'Taken off the site.');
    }

    public function destroy(Solution $solution, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($solution);
        $solution->delete();

        return redirect()->route('admin.solutions.index')->with('success', 'Solution deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Solution $solution = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/', Rule::unique('solutions', 'slug')->ignore($solution?->id)],
            'solution_category_id' => ['required', 'integer', Rule::exists('solution_categories', 'id')],
            'tagline' => ['required', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],

            'industries' => ['array'],
            'industries.*' => ['string', 'max:80'],
            'platforms' => ['array'],
            'platforms.*' => ['string', 'max:80'],
            'tech_stack' => ['array'],
            'tech_stack.*' => ['string', 'max:80'],
            'modules' => ['array'],
            'modules.*' => ['string', 'max:120'],
            'outcomes' => ['array'],
            'outcomes.*' => ['string', 'max:300'],
            'integrations' => ['array'],
            'integrations.*' => ['string', 'max:120'],

            'features' => ['array'],
            'features.*.title' => ['required', 'string', 'max:160'],
            'features.*.body' => ['required', 'string', 'max:600'],

            'price_band_min' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'price_band_max' => ['nullable', 'integer', 'min:0', 'max:1000000000', 'gte:price_band_min'],
            'timeline_weeks_min' => ['nullable', 'integer', 'min:1', 'max:520'],
            'timeline_weeks_max' => ['nullable', 'integer', 'min:1', 'max:520', 'gte:timeline_weeks_min'],

            'accent' => ['required', Rule::in($this->accents())],
            'needs_api_keys' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_published' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'price_band_max.gte' => 'The upper figure must not be below the lower one.',
            'timeline_weeks_max.gte' => 'The longer timeline must not be below the shorter one.',
        ]);

        $validated['slug'] = ($validated['slug'] ?? null) ?: Str::slug($validated['title']);
        $validated['sort_order'] ??= 0;

        // Search metadata follows the copy rather than being maintained twice.
        $validated['seo'] = [
            'title' => $validated['title'].' — custom software by Unboundbyte',
            'description' => Str::limit($validated['summary'], 150),
        ];

        return $validated;
    }

    protected function table(): Table
    {
        return Table::for(Solution::query()->with('category:id,name'))
            ->searchable(['title', 'tagline', 'summary'])
            ->defaultSort('sort_order')
            ->exportName('solutions')
            ->columns([
                Column::make('title', 'Title')->sortable(),
                Column::make('category', 'Category'),
                Column::make('state', 'State'),
                Column::make('modules', 'Modules')->numeric(),
                Column::make('price_band', 'Budget band'),
                Column::make('sort_order', 'Order')->sortable()->numeric(),
                Column::make('updated_at', 'Updated')->sortable(),
            ])
            ->filters([
                Filter::select('solution_category_id', $this->categoryOptions()->map(fn ($c) => ['value' => $c['value'], 'label' => $c['label']])->all(), 'Category')
                    ->placeholder('Any category'),
                Filter::boolean('is_published', 'Live on the site only'),
                Filter::boolean('is_featured', 'Featured only'),
            ])
            ->transform(fn (Solution $solution) => [
                'id' => $solution->id,
                'slug' => $solution->slug,
                'title' => $solution->title,
                'category' => $solution->category?->name,
                'state' => $solution->is_published ? 'Live' : 'Draft',
                'published' => $solution->is_published,
                'featured' => $solution->is_featured,
                'modules' => count($solution->modules ?? []),
                'price_band' => $solution->priceBand() ?? '—',
                'sort_order' => $solution->sort_order,
                'updated_at' => $solution->updated_at?->diffForHumans(),
            ]);
    }

    protected function categoryOptions()
    {
        return SolutionCategory::query()
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (SolutionCategory $category) => ['value' => $category->id, 'label' => $category->name]);
    }

    /** @return array<int, string> */
    protected function accents(): array
    {
        return ['brand', 'accent', 'violet', 'emerald', 'amber', 'rose'];
    }
}
