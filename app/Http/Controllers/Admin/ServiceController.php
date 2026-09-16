<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three service pages.
 *
 * There are few enough of these that a list and a form is the whole screen; a
 * paginated table would be more machinery than the content justifies.
 */
class ServiceController extends Controller
{
    public const TYPES = ['development', 'upgrade', 'maintenance'];

    public function index(): Response
    {
        return Inertia::render('admin/catalogue/services/Index', [
            'services' => Service::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Service $service) => [
                    'id' => $service->id,
                    'title' => $service->title,
                    'slug' => $service->slug,
                    'type' => $service->type,
                    'tagline' => $service->tagline,
                    'isPublished' => $service->is_published,
                    'deliverableCount' => count($service->deliverables ?? []),
                    'modelCount' => count($service->engagement_models ?? []),
                    'updatedAt' => $service->updated_at?->diffForHumans(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/catalogue/services/Form', [
            'service' => null,
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $service = Service::query()->create($this->validated($request));
        $auditor->created($service);

        return redirect()->route('admin.services.edit', $service)->with('success', 'Service created.');
    }

    public function edit(Service $service): Response
    {
        return Inertia::render('admin/catalogue/services/Form', [
            'service' => [
                'id' => $service->id,
                'title' => $service->title,
                'slug' => $service->slug,
                'type' => $service->type,
                'tagline' => $service->tagline,
                'summary' => $service->summary,
                'description' => $service->description,
                'deliverables' => $service->deliverables ?? [],
                'engagement_models' => $service->engagement_models ?? [],
                'process' => $service->process ?? [],
                'faqs' => $service->faqs ?? [],
                'icon' => $service->icon,
                'sort_order' => $service->sort_order,
                'is_published' => $service->is_published,
                'publicUrl' => "/services/{$service->slug}",
            ],
            'types' => self::TYPES,
        ]);
    }

    public function update(Request $request, Service $service, Auditor $auditor): RedirectResponse
    {
        $service->fill($this->validated($request, $service));
        $auditor->updated($service);
        $service->save();

        return back()->with('success', 'Service saved.');
    }

    public function destroy(Service $service, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($service);
        $service->delete();

        return redirect()->route('admin.services.index')->with('success', 'Service deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Service $service = null): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9-]+$/', Rule::unique('services', 'slug')->ignore($service?->id)],
            'type' => ['required', Rule::in(self::TYPES)],
            'tagline' => ['required', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:600'],
            'description' => ['nullable', 'string', 'max:20000'],

            'deliverables' => ['array'],
            'deliverables.*' => ['string', 'max:300'],

            'engagement_models' => ['array'],
            'engagement_models.*.name' => ['required', 'string', 'max:120'],
            'engagement_models.*.body' => ['required', 'string', 'max:600'],
            'engagement_models.*.suits' => ['nullable', 'string', 'max:200'],

            'process' => ['array'],
            'process.*.step' => ['required', 'string', 'max:120'],
            'process.*.body' => ['required', 'string', 'max:600'],

            'faqs' => ['array'],
            'faqs.*.q' => ['required', 'string', 'max:300'],
            'faqs.*.a' => ['required', 'string', 'max:2000'],

            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['boolean'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
        ]);

        $validated['slug'] = ($validated['slug'] ?? null) ?: Str::slug($validated['title']);
        $validated['sort_order'] ??= 0;

        $validated['seo'] = [
            'title' => $validated['title'].' — Unboundbyte Solutions',
            'description' => Str::limit($validated['summary'], 150),
        ];

        return $validated;
    }
}
