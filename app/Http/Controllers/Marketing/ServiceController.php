<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\Seo;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/services/Index', [
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
                    'deliverables' => array_slice($service->deliverables ?? [], 0, 4),
                    'engagementModels' => collect($service->engagement_models ?? [])->pluck('name'),
                ]),
            'seo' => Seo::for(
                'Services — development, modernisation and maintenance',
                'We build custom software, modernise systems you already run, and maintain them afterwards under a contract with a stated response time.',
                '/services',
                Seo::breadcrumbs([
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'Services', 'href' => '/services'],
                ]),
            ),
        ]);
    }

    public function show(Service $service): Response
    {
        abort_unless($service->is_published, 404);

        return Inertia::render('marketing/services/Show', [
            'service' => [
                'title' => $service->title,
                'slug' => $service->slug,
                'type' => $service->type,
                'tagline' => $service->tagline,
                'summary' => $service->summary,
                'description' => $service->description,
                'deliverables' => $service->deliverables ?? [],
                'engagementModels' => $service->engagement_models ?? [],
                'process' => $service->process ?? [],
                'faqs' => $service->faqs ?? [],
                'icon' => $service->icon,
            ],
            'otherServices' => Service::query()
                ->published()
                ->whereKeyNot($service->getKey())
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Service $other) => [
                    'title' => $other->title,
                    'slug' => $other->slug,
                    'tagline' => $other->tagline,
                    'icon' => $other->icon,
                ]),
            'seo' => Seo::for(
                $service->seo['title'] ?? $service->title,
                $service->seo['description'] ?? $service->summary,
                "/services/{$service->slug}",
            ),
        ]);
    }
}
