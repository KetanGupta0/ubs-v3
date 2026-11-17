<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SatisfactionSurvey;
use App\Models\SupportTicket;
use App\Models\Testimonial;
use App\Services\Admin\Auditor;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * How satisfied clients are (CL18): ticket ratings, and what they said at
 * handover. A quote becomes a testimonial draft only with their written
 * consent, and only as they asked to be credited.
 */
class SatisfactionController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(SatisfactionSurvey::query()->with('project:id,name', 'client.profile', 'user:id,name'))
            ->defaultSort('-created_at')
            ->exportName('handover-surveys')
            ->columns([
                Column::make('created_at', 'When')->sortable(),
                Column::make('project', 'Project'),
                Column::make('client', 'Client'),
                Column::make('rating', 'Rating')->sortable(),
                Column::make('quote', 'What they said'),
            ])
            ->transform(fn (SatisfactionSurvey $survey) => [
                'id' => $survey->id,
                'created_at' => $survey->created_at->format('j M Y'),
                'project' => $survey->project?->name,
                'client' => $survey->client?->organisationName(),
                'by' => $survey->user?->name,
                'rating' => $survey->rating,
                'quote' => $survey->quote ?? $survey->went_well ?? '—',
                'wentWell' => $survey->went_well,
                'couldImprove' => $survey->could_improve,
                'consent' => $survey->consent_to_publish,
                'creditAs' => $survey->credit_as,
                'testimonialId' => $survey->testimonial_id,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        $rated = SupportTicket::query()->whereNotNull('satisfaction')->where('closed_at', '>=', now()->subDays(90));

        return Inertia::render('admin/Satisfaction', [
            'table' => $table->toArray($request),
            'tickets' => [
                'count' => (clone $rated)->count(),
                'average' => ($average = (clone $rated)->avg('satisfaction')) ? round((float) $average, 1) : null,
                'recent' => (clone $rated)->whereNotNull('satisfaction_comment')->latest('closed_at')->take(10)->get(['id', 'reference', 'subject', 'satisfaction', 'satisfaction_comment'])
                    ->map(fn (SupportTicket $ticket) => ['id' => $ticket->id, 'reference' => $ticket->reference, 'subject' => $ticket->subject, 'rating' => $ticket->satisfaction, 'comment' => $ticket->satisfaction_comment]),
            ],
            'canMakeTestimonials' => $request->user()->hasPermission('content.manage'),
        ]);
    }

    /** A draft testimonial from a consented quote, unpublished until somebody publishes it. */
    public function testimonial(Request $request, SatisfactionSurvey $survey, Auditor $auditor): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('content.manage'), 403);
        abort_unless($survey->consent_to_publish && filled($survey->quote) && $survey->testimonial_id === null, 422, 'Only a quote the client consented to publish can become a testimonial, once.');

        $testimonial = Testimonial::query()->create([
            'author_name' => $survey->credit_as,
            'company' => $survey->client?->organisationName(),
            'quote' => $survey->quote,
            'rating' => $survey->rating,
            'is_published' => false,
        ]);
        $survey->forceFill(['testimonial_id' => $testimonial->id])->save();
        $auditor->action('testimonial.drafted_from_survey', $testimonial, ['survey' => $survey->id], $survey->credit_as);

        return back()->with('success', 'Added as a draft testimonial. Publish it from Content when you are ready.');
    }
}
