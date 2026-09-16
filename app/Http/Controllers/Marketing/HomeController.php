<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Service;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Models\Testimonial;
use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('marketing/Home', [
            'featuredSolutions' => Solution::query()
                ->published()
                ->where('is_featured', true)
                ->with('category')
                ->orderBy('sort_order')
                ->limit(4)
                ->get()
                ->map->toCardArray()
                ->values(),

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

            'services' => Service::query()
                ->published()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Service $service) => [
                    'title' => $service->title,
                    'slug' => $service->slug,
                    'type' => $service->type,
                    'tagline' => $service->tagline,
                    'summary' => $service->summary,
                    'icon' => $service->icon,
                ]),

            'featuredCourses' => Course::query()
                ->publiclyVisible()
                ->taught()
                ->where('is_featured', true)
                ->with('batches')
                ->orderBy('sort_order')
                ->limit(3)
                ->get()
                ->map->toCardArray()
                ->values(),

            'featuredInternships' => Course::query()
                ->publiclyVisible()
                ->internships()
                ->where('is_featured', true)
                ->with('batches')
                ->orderBy('sort_order')
                ->limit(3)
                ->get()
                ->map->toCardArray()
                ->values(),

            'stats' => [
                'solutions' => Solution::query()->published()->count(),
                'categories' => SolutionCategory::query()->count(),
                'courses' => Course::query()->publiclyVisible()->taught()->count(),
                'internships' => Course::query()->publiclyVisible()->internships()->count(),
            ],

            /*
             * Empty until there is a real client or student willing to be
             * quoted. The section does not render without one, because an
             * invented endorsement on a real company's site is a lie told to
             * people deciding whether to trust us.
             */
            'testimonials' => Testimonial::query()
                ->published()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Testimonial $testimonial) => [
                    'quote' => $testimonial->quote,
                    'author' => $testimonial->author_name,
                    'role' => trim(($testimonial->author_role ?? '').($testimonial->company ? ', '.$testimonial->company : ''), ', '),
                    'rating' => $testimonial->rating,
                ]),

            'seo' => Seo::for(
                'Unboundbyte Solutions — custom software and live technical training',
                'We build and maintain custom software, and we train the people who run it. Browse the solutions we build, or join a live programme taught by working developers.',
                '/',
                Seo::organisation(),
            ),
        ]);
    }
}
