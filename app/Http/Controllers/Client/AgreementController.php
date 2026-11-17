<?php

namespace App\Http\Controllers\Client;

use App\Models\Agreement;
use App\Services\Client\ClientDocuments;
use App\Services\Client\Signatures;
use App\Support\Markdown;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The organisation's agreements (CL1): everything ever sent to it, never a
 * draft, each readable and downloadable as the PDF that was sent.
 */
class AgreementController extends ClientController
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/agreements/Index', [
            'agreements' => Agreement::query()
                ->forClient($this->client($request))
                ->visibleToClient()
                ->with('project:id,name')
                ->latest('sent_at')
                ->get()
                ->map(fn (Agreement $agreement) => [
                    'id' => $agreement->id,
                    'number' => $agreement->number,
                    'version' => $agreement->version,
                    'title' => $agreement->title,
                    'kind' => $agreement->kindLabel(),
                    'status' => $agreement->status,
                    'statusLabel' => $agreement->statusLabel(),
                    'project' => $agreement->project?->name,
                    'sentAt' => $agreement->sent_at?->format('j M Y'),
                ]),
        ]);
    }

    public function show(Request $request, int $agreement, Signatures $signatures): Response
    {
        $record = $this->own($request, Agreement::query()
            ->forClient($this->client($request))
            ->visibleToClient()
            ->with('project:id,name', 'previous:id,number', 'signatures'), $agreement);

        $signatures->markViewed($record, $request->user());

        return Inertia::render('client/agreements/Show', [
            'agreement' => [
                'id' => $record->id,
                'number' => $record->number,
                'version' => $record->version,
                'title' => $record->title,
                'kind' => $record->kindLabel(),
                'status' => $record->status,
                'statusLabel' => $record->statusLabel(),
                'html' => Markdown::html($record->body),
                'project' => $record->project ? ['id' => $record->project->id, 'name' => $record->project->name] : null,
                'effectiveOn' => $record->effective_on?->format('j M Y'),
                'expiresOn' => $record->expires_on?->format('j M Y'),
                'sentAt' => $record->sent_at?->format('j M Y'),
                'sha256' => $record->pdf_sha256,
                'previous' => $record->previous ? ['id' => $record->previous->id, 'number' => $record->previous->number] : null,
                'replacedBy' => Agreement::query()->where('previous_id', $record->id)->visibleToClient()->value('id'),
                'signatures' => $record->signatures->map->toCertificateArray()->values(),
                'hasSignedCopy' => $record->signed_pdf_path !== null,
            ],
            // Only a signatory or an owner signs, and only what is waiting.
            'canSign' => $record->status === 'sent' && $request->user()->canAsClient('sign'),
        ]);
    }

    public function pdf(Request $request, int $agreement, ClientDocuments $documents, Signatures $signatures): HttpResponse
    {
        $record = $this->own($request, Agreement::query()->forClient($this->client($request))->visibleToClient(), $agreement);
        $signatures->markViewed($record, $request->user());

        return response($documents->stored($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'.pdf"',
        ]);
    }

    /* ---------------------------------------------------------- signing */

    public function signCode(Request $request, int $agreement, Signatures $signatures): JsonResponse
    {
        $this->mayAsClient($request, 'sign');
        $record = $this->own($request, Agreement::query()->forClient($this->client($request))->visibleToClient(), $agreement);

        try {
            return response()->json(['sentTo' => $signatures->requestCode($request->user(), $record, 'client', $request), 'sha256' => $record->pdf_sha256]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sign(Request $request, int $agreement, Signatures $signatures): RedirectResponse
    {
        $this->mayAsClient($request, 'sign');
        $record = $this->own($request, Agreement::query()->forClient($this->client($request))->visibleToClient(), $agreement);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:160'],
            'designation' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm that you have read it and agree to it.',
            'name.required' => 'Type your full name as your signature.',
        ]);

        try {
            $signatures->sign($request->user(), $record, 'client', $validated['name'], $validated['designation'], $validated['code'], $request);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('success', 'Signed. We will countersign it, and you will have the copy signed by both.');
    }

    /** The signed copy, with its certificate of signing. */
    public function signed(Request $request, int $agreement): HttpResponse
    {
        $record = $this->own($request, Agreement::query()->forClient($this->client($request))->visibleToClient(), $agreement);
        abort_unless($record->signed_pdf_path !== null, 404);

        return response(Storage::disk('private')->get($record->signed_pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'-signed.pdf"',
        ]);
    }
}
