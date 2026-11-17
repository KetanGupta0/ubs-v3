<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Admin\ChangeRequestController as AdminChanges;
use App\Models\ChangeRequest;
use App\Models\Project;
use App\Services\Client\ChangeRequests;
use App\Services\Client\ClientDocuments;
use App\Services\Client\Signatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Changes to a project's scope, from the client's side (CL12). Anybody in
 * the organisation may ask; a signatory or an owner approves by signing
 * the addendum, or declines.
 */
class ChangeRequestController extends ClientController
{
    public function store(Request $request, int $project, ChangeRequests $changes): RedirectResponse
    {
        $owned = $this->own($request, Project::query()->forClient($this->client($request)), $project);

        $validated = $this->validatedInput($request, [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
        ], [
            'description.required' => 'Describe the change, so we can estimate it.',
        ]);

        $change = $changes->raise($owned, $request->user(), $validated['title'], $validated['description']);

        return redirect("/client/projects/{$owned->id}/changes/{$change->id}")
            ->with('success', 'Sent. We will estimate it and send it to you to approve.');
    }

    public function show(Request $request, int $project, int $change, Signatures $signatures): Response
    {
        $record = $this->ownChange($request, $project, $change);
        $record->load('project:id,name,code', 'decider:id,name', 'signatures', 'milestone:id');
        $signatures->markViewed($record, $request->user());

        return Inertia::render('client/projects/Change', [
            'change' => [...AdminChanges::forScreen($record), 'milestoneId' => $record->milestone?->id],
            'canAnswer' => $record->canBeAnswered() && $request->user()->canAsClient('approve'),
        ]);
    }

    public function pdf(Request $request, int $project, int $change, ClientDocuments $documents, Signatures $signatures): HttpResponse
    {
        $record = $this->ownChange($request, $project, $change);
        abort_unless($record->pdf_path !== null, 404);
        $signatures->markViewed($record, $request->user());

        return response($documents->stored($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'.pdf"',
        ]);
    }

    public function signCode(Request $request, int $project, int $change, Signatures $signatures): JsonResponse
    {
        $this->mayAsClient($request, 'approve');
        $record = $this->ownChange($request, $project, $change);

        try {
            return response()->json(['sentTo' => $signatures->requestCode($request->user(), $record, 'client', $request), 'sha256' => $record->pdf_sha256]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sign(Request $request, int $project, int $change, Signatures $signatures): RedirectResponse
    {
        $this->mayAsClient($request, 'approve');
        $record = $this->ownChange($request, $project, $change);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:160'],
            'designation' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm that you approve the change for your organisation.',
            'name.required' => 'Type your full name as your signature.',
        ]);

        try {
            $signatures->sign($request->user(), $record, 'client', $validated['name'], $validated['designation'], $validated['code'], $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('success', 'Approved and signed. The project has been updated, and the work is a new milestone.');
    }

    public function decline(Request $request, int $project, int $change, ChangeRequests $changes): RedirectResponse
    {
        $this->mayAsClient($request, 'approve');
        $record = $this->ownChange($request, $project, $change);

        $validated = $this->validatedInput($request, [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'reason.required' => 'Tell us why, so a new estimate can fit.',
        ]);

        try {
            $changes->decline($record, $request->user(), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Declined. We will come back to you.');
    }

    public function signed(Request $request, int $project, int $change): HttpResponse
    {
        $record = $this->ownChange($request, $project, $change);
        abort_unless($record->signed_pdf_path !== null, 404);

        return response(Storage::disk('private')->get($record->signed_pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'-signed.pdf"',
        ]);
    }

    /** A change request on one of the organisation's own projects, or not found. */
    protected function ownChange(Request $request, int $project, int $change): ChangeRequest
    {
        $owned = $this->own($request, Project::query()->forClient($this->client($request)), $project);

        return ChangeRequest::query()->where('project_id', $owned->id)->whereKey($change)->firstOrFail()->setRelation('project', $owned);
    }
}
