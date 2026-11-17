<?php

namespace App\Http\Controllers\Client;

use App\Models\Handover;
use App\Models\Project;
use App\Models\SatisfactionSurvey;
use App\Services\Client\Handovers;
use App\Support\Markdown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The handover pack as the client reads it (CL15): what was published,
 * acknowledged by a signatory or an owner, then a short survey (CL18).
 */
class HandoverController extends ClientController
{
    public function show(Request $request, int $project): Response
    {
        [$owned, $handover] = $this->ownHandover($request, $project);
        $content = $handover->published_content ?? [];

        return Inertia::render('client/projects/Handover', [
            'project' => ['id' => $owned->id, 'name' => $owned->name, 'code' => $owned->code],
            'handover' => [
                'revision' => $handover->revision,
                'publishedAt' => $handover->published_at->format('j M Y'),
                'repository' => $content['repository'] ?? null,
                'deploymentGuide' => filled($content['deployment_guide'] ?? null) ? Markdown::html($content['deployment_guide']) : null,
                'environmentNotes' => filled($content['environment_notes'] ?? null) ? Markdown::html($content['environment_notes']) : null,
                'credentialsNote' => $content['credentials_note'] ?? null,
                'licences' => $content['licences'] ?? [],
                'videos' => $content['videos'] ?? [],
                'warrantyEndsOn' => filled($content['warranty_ends_on'] ?? null) ? Carbon::parse($content['warranty_ends_on'])->format('j M Y') : null,
                'acknowledged' => $handover->isAcknowledged(),
                'acknowledgedBy' => $handover->acknowledger?->name,
                'acknowledgedAt' => $handover->acknowledged_at?->format('j M Y'),
            ],
            'canAcknowledge' => ! $handover->isAcknowledged() && $request->user()->canAsClient('accept'),
            'surveyAnswered' => SatisfactionSurvey::query()->where('project_id', $owned->id)->where('user_id', $request->user()->id)->exists(),
        ]);
    }

    public function acknowledge(Request $request, int $project, Handovers $handovers): RedirectResponse
    {
        $this->mayAsClient($request, 'accept');
        [, $handover] = $this->ownHandover($request, $project);

        try {
            $handovers->acknowledge($handover, $request->user(), $request);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acknowledged. It stays here whenever you need it.');
    }

    public function survey(Request $request, int $project, Handovers $handovers): RedirectResponse
    {
        [$owned] = $this->ownHandover($request, $project);

        $validated = $this->validatedInput($request, [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'went_well' => ['nullable', 'string', 'max:2000'],
            'could_improve' => ['nullable', 'string', 'max:2000'],
            'quote' => ['nullable', 'string', 'max:600'],
            'consent_to_publish' => ['boolean'],
            'credit_as' => ['nullable', 'required_if:consent_to_publish,true', 'string', 'max:160'],
        ], [
            'credit_as.required_if' => 'Say how you would like to be credited, such as your name, role and company.',
        ]);

        try {
            $handovers->survey($owned, $request->user(), $validated);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Thank you. That helps us more than you might think.');
    }

    /** @return array{0: Project, 1: Handover} A published handover of one of the organisation's projects, or not found. */
    protected function ownHandover(Request $request, int $project): array
    {
        $owned = $this->own($request, Project::query()->forClient($this->client($request)), $project);
        $handover = Handover::query()->where('project_id', $owned->id)->whereNotNull('published_at')->with('acknowledger:id,name')->firstOrFail();

        return [$owned, $handover];
    }
}
