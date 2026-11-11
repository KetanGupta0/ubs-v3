<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LegalDocument;
use App\Models\Page;
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
            ['loc' => url('/internships'), 'priority' => '0.9', 'changefreq' => 'weekly'],
            ['loc' => url('/for-colleges'), 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => url('/about'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/process'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/technology'), 'priority' => '0.6', 'changefreq' => 'monthly'],
            ['loc' => url('/faq'), 'priority' => '0.7', 'changefreq' => 'monthly'],
            ['loc' => url('/contact'), 'priority' => '0.8', 'changefreq' => 'yearly'],
        ]);

        // The version in force of each published legal document; earlier
        // versions stay readable but out of search.
        $urls = $urls->concat(collect(LegalDocument::published())->map(fn (array $document) => [
            'loc' => url($document['href']),
            'lastmod' => $document['publishedAt'],
            'priority' => '0.3',
            'changefreq' => 'yearly',
        ]));

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
            // Only publicly visible offerings, each under the path the site
            // actually serves it from.
            ->concat(Course::query()->publiclyVisible()->taught()->get()->map(fn (Course $c) => [
                'loc' => url("/training/{$c->slug}"),
                'lastmod' => $c->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]))
            ->concat(Course::query()->publiclyVisible()->internships()->get()->map(fn (Course $c) => [
                'loc' => url("/internships/{$c->slug}"),
                'lastmod' => $c->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]))
            // Published pages from the admin panel, unless kept out of search.
            ->concat(Page::query()->published()->where('is_indexable', true)->get()->map(fn (Page $p) => [
                'loc' => url($p->path()),
                'lastmod' => $p->updated_at?->toAtomString(),
                'priority' => '0.6',
                'changefreq' => 'monthly',
            ]));

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }
}
