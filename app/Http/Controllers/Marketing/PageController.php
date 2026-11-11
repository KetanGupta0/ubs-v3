<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Admin\SitePageController;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Faq;
use App\Models\Page;
use App\Models\Service;
use App\Models\Solution;
use App\Support\Seo;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function about(): Response
    {
        return Inertia::render('marketing/About', [
            'seo' => Seo::for(
                'About Unboundbyte Solutions',
                'A software company that also teaches. We build and maintain systems, and we train the people who will run them.',
                '/about',
            ),
        ]);
    }

    public function process(): Response
    {
        return Inertia::render('marketing/Process', [
            'seo' => Seo::for(
                'How we work',
                'Discovery, a clickable prototype, two week increments you can open, hardening, and a handover that leaves you able to replace us.',
                '/process',
            ),
        ]);
    }

    public function technology(): Response
    {
        return Inertia::render('marketing/Technology', [
            'seo' => Seo::for(
                'Technology we build on',
                'Laravel, Vue.js, MySQL, Redis and the infrastructure around them. Boring, well understood tools chosen so your system can be maintained by someone other than us.',
                '/technology',
            ),
        ]);
    }

    public function faq(): Response
    {
        $faqs = Faq::query()->published()->orderBy('sort_order')->get();

        return Inertia::render('marketing/Faq', [
            'groups' => $faqs
                ->groupBy('group')
                ->map(fn ($items, $group) => [
                    'group' => $group,
                    'label' => str($group)->headline()->toString(),
                    'items' => $items->map(fn (Faq $faq) => [
                        'question' => $faq->question,
                        'answer' => $faq->answer,
                    ])->values(),
                ])
                ->values(),
            'seo' => Seo::for(
                'Frequently asked questions',
                'Costs, ownership of code, how projects run, how training works, and what a maintenance contract actually covers.',
                '/faq',
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => $faqs->map(fn (Faq $faq) => [
                        '@type' => 'Question',
                        'name' => $faq->question,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq->answer],
                    ])->values()->all(),
                ],
            ),
        ]);
    }

    public function contact(): Response
    {
        return Inertia::render('marketing/Contact', [
            'solutions' => Solution::query()
                ->published()
                ->orderBy('title')
                ->get(['title', 'slug'])
                ->map(fn (Solution $s) => ['value' => $s->slug, 'label' => $s->title]),
            'services' => Service::query()
                ->published()
                ->orderBy('sort_order')
                ->get(['title', 'slug'])
                ->map(fn (Service $s) => ['value' => $s->slug, 'label' => $s->title]),
            'courses' => Course::query()
                ->publiclyVisible()
                ->orderBy('sort_order')
                ->get(['title', 'slug'])
                ->map(fn (Course $c) => ['value' => $c->slug, 'label' => $c->title]),
            'seo' => Seo::for(
                'Contact Unboundbyte Solutions',
                'Tell us what you are trying to build, or which programme you want to join. We reply within one working day.',
                '/contact',
            ),
        ]);
    }

    /** A page written in the admin panel. Only published ones exist as far as visitors know. */
    public function page(string $slug): Response
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        return Inertia::render('marketing/CmsPage', [
            'page' => SitePageController::publicArray($page),
            'preview' => false,
            'seo' => [
                ...Seo::for(($page->seo_title ?: $page->title).' — Unboundbyte Solutions', $page->description(), $page->path()),
                'noindex' => ! $page->is_indexable,
            ],
        ]);
    }
}
