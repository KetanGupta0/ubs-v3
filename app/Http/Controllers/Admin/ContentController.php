<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\Testimonial;
use App\Services\Admin\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Frequently asked questions and testimonials.
 *
 * Both are small enough to edit inline rather than on their own pages.
 *
 * Testimonials start unpublished and there is no way to generate one. They can
 * only be typed in by somebody who has an actual quote from an actual person,
 * which is the point: an invented endorsement on a real company's site is a lie
 * told to whoever is deciding whether to trust us.
 */
class ContentController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Content', [
            'faqs' => Faq::query()
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Faq $faq) => [
                    'id' => $faq->id,
                    'question' => $faq->question,
                    'answer' => $faq->answer,
                    'group' => $faq->group,
                    'sortOrder' => $faq->sort_order,
                    'isPublished' => $faq->is_published,
                ]),
            'groups' => Faq::query()->distinct()->orderBy('group')->pluck('group'),
            'testimonials' => Testimonial::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Testimonial $testimonial) => [
                    'id' => $testimonial->id,
                    'authorName' => $testimonial->author_name,
                    'authorRole' => $testimonial->author_role,
                    'company' => $testimonial->company,
                    'quote' => $testimonial->quote,
                    'rating' => $testimonial->rating,
                    'isPublished' => $testimonial->is_published,
                    'sortOrder' => $testimonial->sort_order,
                ]),
        ]);
    }

    public function storeFaq(Request $request, Auditor $auditor): RedirectResponse
    {
        $faq = Faq::query()->create($this->faqRules($request));
        $auditor->created($faq);

        return back()->with('success', 'Question added.');
    }

    public function updateFaq(Request $request, Faq $faq, Auditor $auditor): RedirectResponse
    {
        $faq->fill($this->faqRules($request));
        $auditor->updated($faq);
        $faq->save();

        return back()->with('success', 'Question saved.');
    }

    public function destroyFaq(Faq $faq, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($faq);
        $faq->delete();

        return back()->with('success', 'Question removed.');
    }

    public function storeTestimonial(Request $request, Auditor $auditor): RedirectResponse
    {
        $testimonial = Testimonial::query()->create($this->testimonialRules($request));
        $auditor->created($testimonial, $testimonial->author_name);

        return back()->with('success', 'Testimonial added. It is not live until you publish it.');
    }

    public function updateTestimonial(Request $request, Testimonial $testimonial, Auditor $auditor): RedirectResponse
    {
        $testimonial->fill($this->testimonialRules($request));
        $auditor->updated($testimonial, label: $testimonial->author_name);
        $testimonial->save();

        return back()->with('success', 'Testimonial saved.');
    }

    public function destroyTestimonial(Testimonial $testimonial, Auditor $auditor): RedirectResponse
    {
        $auditor->deleted($testimonial, $testimonial->author_name);
        $testimonial->delete();

        return back()->with('success', 'Testimonial removed.');
    }

    protected function faqRules(Request $request): array
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:4000'],
            'group' => ['required', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['boolean'],
        ]);

        $validated['sort_order'] ??= 0;

        return $validated;
    }

    protected function testimonialRules(Request $request): array
    {
        $validated = $request->validate([
            'author_name' => ['required', 'string', 'max:120'],
            'author_role' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'quote' => ['required', 'string', 'max:1000'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_published' => ['boolean'],
        ]);

        $validated['sort_order'] ??= 0;

        return $validated;
    }
}
