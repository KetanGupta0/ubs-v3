<?php

namespace App\Http\Controllers\Client;

use App\Models\Agreement;
use App\Services\Client\ClientDocuments;
use App\Support\Markdown;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
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

    public function show(Request $request, int $agreement): Response
    {
        $record = $this->own($request, Agreement::query()
            ->forClient($this->client($request))
            ->visibleToClient()
            ->with('project:id,name', 'previous:id,number'), $agreement);

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
            ],
        ]);
    }

    public function pdf(Request $request, int $agreement, ClientDocuments $documents): HttpResponse
    {
        $record = $this->own($request, Agreement::query()->forClient($this->client($request))->visibleToClient(), $agreement);

        return response($documents->stored($record), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'.pdf"',
        ]);
    }
}
