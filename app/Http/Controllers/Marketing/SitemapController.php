<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Service;
use App\Models\Solution;
use Illuminate\Http\Response;

/**
 * The XML sitemap.
 *
 * Generated rather than maintained by hand, so a solution added to the
 * catalogue is discoverable without anyone remembering a second step.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = collect([
            ['loc' => url('/'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => url('/solutions'), 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => url('/services'), 'priority' => '0.9', 'changefreq' => 'monthly'],
            ['loc' => url('/training'), 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => url('/about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/process'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/technology'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/faq'), 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => url('/contact'), 'priority' => '0.8', 'changefreq' => 'yearly'],
            ['loc' => url('/legal/privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => url('/legal/terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ]);

        $urls = $urls
            ->concat(Solution::query()->published()->get()->map(fn (Solution $s) => [
                'loc' => url("/solutions/{$s->slug}"),
                'lastmod' => $s->updated_at?->toAtomString(),
                'priority' => $s->is_featured ? '0.8' : '0.7',
                'changefreq' => 'monthly',
            ]))
            ->concat(Service::query()->published()->get()->map(fn (Service $s) => [
                'loc' => url("/services/{$s->slug}"),
                'lastmod' => $s->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'monthly',
            ]))
            // Only publicly visible courses, matching what the site renders.
            ->concat(Course::query()->publiclyVisible()->get()->map(fn (Course $c) => [
                'loc' => url("/training/{$c->slug}"),
                'lastmod' => $c->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]));

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
