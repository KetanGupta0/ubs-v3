<?php

use App\Models\Solution;
use App\Models\SolutionCategory;

beforeEach(function () {
    $this->category = SolutionCategory::query()->create([
        'name' => 'Business operations', 'slug' => 'business-operations', 'sort_order' => 1,
    ]);

    $this->other = SolutionCategory::query()->create([
        'name' => 'Healthcare', 'slug' => 'healthcare', 'sort_order' => 2,
    ]);

    $this->erp = Solution::query()->create([
        'solution_category_id' => $this->category->id,
        'title' => 'Unified ERP suite',
        'slug' => 'unified-erp-suite',
        'tagline' => 'One system instead of nine spreadsheets',
        'summary' => 'Purchase, production, stock and accounts in one database.',
        'industries' => ['Manufacturing', 'Distribution'],
        'platforms' => ['Web', 'Android'],
        'tech_stack' => ['Laravel', 'Vue.js', 'MySQL'],
        'modules' => ['Purchase', 'Inventory'],
        'price_band_min' => 600000,
        'price_band_max' => 2500000,
        'timeline_weeks_min' => 16,
        'timeline_weeks_max' => 32,
        'is_published' => true,
        'is_featured' => true,
    ]);

    $this->clinic = Solution::query()->create([
        'solution_category_id' => $this->other->id,
        'title' => 'Clinic management',
        'slug' => 'clinic-management',
        'tagline' => 'Appointments and records without the paper',
        'summary' => 'Registration to discharge on one patient record.',
        'industries' => ['Clinics', 'Pharmacy'],
        'platforms' => ['Web'],
        'tech_stack' => ['Laravel', 'MySQL'],
        'is_published' => true,
    ]);

    $this->draft = Solution::query()->create([
        'solution_category_id' => $this->category->id,
        'title' => 'Unfinished idea',
        'slug' => 'unfinished-idea',
        'tagline' => 'Not ready',
        'summary' => 'Should never be visible.',
        'is_published' => false,
    ]);
});

it('lists only published solutions', function () {
    $this->get('/solutions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/solutions/Index')
            ->has('solutions', 2)
            ->where('totalCount', 2),
        );
});

it('hides an unpublished solution behind a 404', function () {
    $this->get('/solutions/unfinished-idea')->assertNotFound();
});

it('filters by category', function () {
    $this->get('/solutions?category=healthcare')
        ->assertInertia(fn ($page) => $page
            ->has('solutions', 1)
            ->where('solutions.0.title', 'Clinic management'),
        );
});

it('searches across the title, summary and facets', function () {
    $this->get('/solutions?q=spreadsheets')
        ->assertInertia(fn ($page) => $page->has('solutions', 1)->where('solutions.0.slug', 'unified-erp-suite'));

    // The facet lists are searchable too, so an industry word finds the product.
    $this->get('/solutions?q=Pharmacy')
        ->assertInertia(fn ($page) => $page->has('solutions', 1)->where('solutions.0.slug', 'clinic-management'));
});

it('treats a wildcard in the search term as a literal character', function () {
    $this->get('/solutions?q=%25')
        ->assertInertia(fn ($page) => $page->has('solutions', 0));
});

it('filters by industry, platform and technology', function () {
    $this->get('/solutions?industry=Manufacturing')
        ->assertInertia(fn ($page) => $page->has('solutions', 1)->where('solutions.0.slug', 'unified-erp-suite'));

    $this->get('/solutions?platform=Android')
        ->assertInertia(fn ($page) => $page->has('solutions', 1));

    $this->get('/solutions?tech=Laravel')
        ->assertInertia(fn ($page) => $page->has('solutions', 2));
});

it('matches a facet value whole rather than as a substring', function () {
    Solution::query()->create([
        'solution_category_id' => $this->category->id,
        'title' => 'Other', 'slug' => 'other', 'tagline' => 't', 'summary' => 's',
        'tech_stack' => ['Vue.js'],
        'is_published' => true,
    ]);

    // "Vue" must not match "Vue.js", or every filter would be a prefix search.
    $this->get('/solutions?tech=Vue')
        ->assertInertia(fn ($page) => $page->has('solutions', 0));
});

it('combines filters', function () {
    $this->get('/solutions?category=business-operations&tech=Laravel')
        ->assertInertia(fn ($page) => $page->has('solutions', 1)->where('solutions.0.slug', 'unified-erp-suite'));

    $this->get('/solutions?category=healthcare&industry=Manufacturing')
        ->assertInertia(fn ($page) => $page->has('solutions', 0));
});

it('builds facet options from published rows only', function () {
    $this->get('/solutions')->assertInertia(function ($page) {
        $industries = $page->toArray()['props']['facets']['industries'];

        // The unpublished row contributes nothing.
        expect($industries)->toContain('Manufacturing')
            ->and($industries)->toContain('Clinics')
            ->and($industries)->not->toContain('Unfinished');
    });
});

it('shows a product page with its modules and related products', function () {
    Solution::query()->create([
        'solution_category_id' => $this->category->id,
        'title' => 'Sibling product', 'slug' => 'sibling', 'tagline' => 't', 'summary' => 's',
        'is_published' => true,
    ]);

    $this->get('/solutions/unified-erp-suite')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/solutions/Show')
            ->where('solution.title', 'Unified ERP suite')
            ->has('solution.modules', 2)
            // Related comes from the same category, excluding this one.
            ->has('related', 1)
            ->where('related.0.slug', 'sibling')
            ->has('seo.structuredData'),
        );
});

it('renders a budget band rather than a single figure', function () {
    expect($this->erp->priceBand())->toBe('₹6L – ₹25L')
        ->and($this->erp->timeline())->toBe('16–32 weeks');
});

it('omits the budget band when no figures are set', function () {
    expect($this->clinic->priceBand())->toBeNull()
        ->and($this->clinic->timeline())->toBeNull();
});
