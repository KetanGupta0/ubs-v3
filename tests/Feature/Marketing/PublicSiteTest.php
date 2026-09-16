<?php

use App\Models\Batch;
use App\Models\Course;
use App\Models\Faq;
use App\Models\Service;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Models\Testimonial;

beforeEach(function () {
    $category = SolutionCategory::query()->create(['name' => 'Ops', 'slug' => 'ops']);

    Solution::query()->create([
        'solution_category_id' => $category->id,
        'title' => 'ERP', 'slug' => 'erp', 'tagline' => 't', 'summary' => 's',
        'is_published' => true, 'is_featured' => true,
    ]);

    Service::query()->create([
        'title' => 'Custom software development', 'slug' => 'custom-software-development',
        'type' => 'development', 'tagline' => 't', 'summary' => 's',
        'deliverables' => ['Source code in your repository'],
        'engagement_models' => [['name' => 'Fixed scope', 'body' => 'b', 'suits' => 's']],
        'is_published' => true,
    ]);

    $this->public = Course::query()->create([
        'title' => 'Full stack', 'slug' => 'full-stack', 'type' => 'programme',
        'tagline' => 't', 'summary' => 's', 'level' => 'beginner',
        'duration_weeks' => 24, 'price' => 45000, 'sale_price' => 35000,
        'visibility' => 'public', 'is_published' => true, 'is_featured' => true,
    ]);

    $this->hidden = Course::query()->create([
        'title' => 'Internal track', 'slug' => 'internal-track', 'type' => 'programme',
        'tagline' => 't', 'summary' => 's', 'level' => 'beginner',
        'visibility' => 'lms_only', 'is_published' => true,
    ]);

    Batch::query()->create([
        'course_id' => $this->public->id, 'name' => 'October batch', 'code' => 'FS-OCT',
        'starts_on' => now()->addWeeks(3), 'schedule' => [['day' => 'Mon', 'from' => '19:30', 'to' => '21:00']],
        'capacity' => 24, 'seats_taken' => 20, 'status' => 'upcoming', 'is_published' => true,
    ]);
});

it('renders the home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/Home')
            ->has('featuredSolutions', 1)
            ->has('services', 1)
            ->has('featuredCourses', 1)
            ->where('stats.solutions', 1),
        );
});

it('renders no testimonials until there is a real one', function () {
    // Inventing an endorsement on a real company's site would be a lie told to
    // someone deciding whether to trust us, so the section stays empty.
    $this->get('/')->assertInertia(fn ($page) => $page->has('testimonials', 0));

    Testimonial::query()->create([
        'author_name' => 'A real client', 'quote' => 'They were straight with us.', 'is_published' => true,
    ]);

    $this->get('/')->assertInertia(fn ($page) => $page->has('testimonials', 1));
});

it('lists services and shows one', function () {
    $this->get('/services')->assertOk()->assertInertia(fn ($page) => $page->has('services', 1));

    $this->get('/services/custom-software-development')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/services/Show')
            ->where('service.title', 'Custom software development'),
        );
});

it('lists only publicly visible courses', function () {
    $this->get('/training')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('courses', 1)
            ->where('courses.0.slug', 'full-stack'),
        );
});

it('refuses to render a course that is LMS only', function () {
    // It is published, so only the visibility flag keeps it off the public site.
    $this->get('/training/internal-track')->assertNotFound();
});

it('shows a course with its next batch and seat count', function () {
    $this->get('/training/full-stack')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('marketing/training/Show')
            ->has('batches', 1)
            ->where('batches.0.seatsLeft', 4)
            ->where('batches.0.nearlyFull', true)
            ->where('course.price', 35000)
            ->where('course.originalPrice', 45000),
        );
});

it('filters courses by level and type', function () {
    $this->get('/training?level=advanced')->assertInertia(fn ($page) => $page->has('courses', 0));
    $this->get('/training?type=programme')->assertInertia(fn ($page) => $page->has('courses', 1));
});

it('renders the supporting pages', function () {
    Faq::query()->create([
        'question' => 'Who owns the code?', 'answer' => 'You do.', 'group' => 'development', 'is_published' => true,
    ]);

    foreach ([
        '/about' => 'marketing/About',
        '/process' => 'marketing/Process',
        '/technology' => 'marketing/Technology',
        '/faq' => 'marketing/Faq',
        '/contact' => 'marketing/Contact',
        '/legal/privacy' => 'marketing/Legal',
        '/legal/terms' => 'marketing/Legal',
    ] as $path => $component) {
        $this->get($path)->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }
});

it('rejects an unknown legal document', function () {
    $this->get('/legal/cookies')->assertNotFound();
});

it('groups the FAQ and publishes structured data for it', function () {
    Faq::query()->create(['question' => 'Q1', 'answer' => 'A1', 'group' => 'general', 'is_published' => true]);
    Faq::query()->create(['question' => 'Q2', 'answer' => 'A2', 'group' => 'training', 'is_published' => true]);
    Faq::query()->create(['question' => 'Hidden', 'answer' => 'A3', 'group' => 'general', 'is_published' => false]);

    $this->get('/faq')->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        expect($props['groups'])->toHaveCount(2)
            ->and($props['seo']['structuredData']['@type'])->toBe('FAQPage')
            ->and($props['seo']['structuredData']['mainEntity'])->toHaveCount(2);
    });
});

it('serves a sitemap listing only what the site renders', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()->assertHeader('content-type', 'application/xml');

    $xml = $response->getContent();

    expect($xml)->toContain('/solutions/erp')
        ->and($xml)->toContain('/training/full-stack')
        // The LMS only course must not be advertised to crawlers either.
        ->and($xml)->not->toContain('/training/internal-track');
});

it('keeps private areas out of robots.txt', function () {
    $robots = file_get_contents(public_path('robots.txt'));

    foreach (['/admin', '/client', '/student', '/settings', '/design'] as $path) {
        expect($robots)->toContain("Disallow: {$path}");
    }
});

it('gives every public page its own metadata', function () {
    foreach (['/', '/solutions', '/solutions/erp', '/services', '/training', '/training/full-stack', '/about', '/faq'] as $path) {
        $this->get($path)->assertInertia(function ($page) use ($path) {
            $seo = $page->toArray()['props']['seo'];

            expect($seo['title'])->not->toBeEmpty("{$path} has no title")
                ->and($seo['description'])->not->toBeEmpty("{$path} has no description")
                // Search engines cut a description off around 160 characters.
                // Counted in characters, not bytes, since the ellipsis is
                // multi byte and a byte count would fail on it.
                ->and(mb_strlen($seo['description']))->toBeLessThanOrEqual(158)
                ->and($seo['url'])->toContain($path === '/' ? '' : $path);
        });
    }
});
