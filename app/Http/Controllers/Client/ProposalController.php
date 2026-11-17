<?php

namespace App\Http\Controllers\Client;

use App\Models\Proposal;
use App\Models\QuotationItem;
use App\Services\Admin\Auditor;
use App\Services\Client\ClientDocuments;
use App\Services\Client\Signatures;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Proposals and their quotations, as the client sees them.
 *
 * Accepting or rejecting is recorded with a timestamp and the address it came
 * from. "We never agreed to that" is a conversation worth being able to end,
 * and it cannot be ended by a status field alone.
 */
class ProposalController extends ClientController
{
    public function index(Request $request): Response
    {
        $proposals = Proposal::query()
            ->where('client_id', $this->client($request)->id)
            ->visibleToClient()
            ->with(['quotation', 'project:id,name'])
            ->latest('id')
            ->get();

        return Inertia::render('client/proposals/Index', [
            'proposals' => $proposals->map(fn (Proposal $proposal) => $this->card($proposal)),
            'awaiting' => $proposals->filter(fn (Proposal $p) => $p->canBeAnswered())->count(),
        ]);
    }

    public function show(Request $request, int $proposal, Signatures $signatures): Response
    {
        $record = $this->own($request, Proposal::query()
            ->where('client_id', $this->client($request)->id)
            ->visibleToClient()
            ->with(['quotation.items', 'project:id,name,code', 'author:id,name', 'signatures']), $proposal);

        $signatures->markViewed($record, $request->user());

        return Inertia::render('client/proposals/Show', [
            'proposal' => [
                ...$this->card($record),
                'summary' => $record->summary,
                'body' => $record->body,
                'assumptions' => $record->assumptions ?? [],
                'deliverables' => $record->deliverables ?? [],
                'timeline' => $record->timeline,
                'project' => $record->project?->name,
                'author' => $record->author?->name,
                'responseNote' => $record->response_note,
                'respondedAt' => $record->responded_at?->format('j M Y, g:i a'),
                'signatures' => $record->signatures->map->toCertificateArray()->values(),
                'hasSignedCopy' => $record->signed_pdf_path !== null,
                'sha256' => $record->pdf_sha256,
            ],

            'quotation' => $record->quotation ? [
                'number' => $record->quotation->number,
                'subtotal' => Money::display($record->quotation->subtotal),
                'discount' => Money::display($record->quotation->discount),
                'tax' => Money::display($record->quotation->tax),
                'total' => Money::display($record->quotation->total),
                'totalInWords' => Money::words($record->quotation->total),
                'notes' => $record->quotation->notes,
                'items' => $record->quotation->items->map(fn (QuotationItem $item) => [
                    'description' => $item->description,
                    'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                    'unit' => $item->unit,
                    'unitPrice' => Money::display($item->unit_price),
                    'taxRate' => (float) $item->tax_rate,
                    'amount' => Money::display($item->amount),
                ]),
            ] : null,
        ]);
    }

    /** The proposal as it was sent, as a PDF. */
    public function pdf(Request $request, int $proposal, ClientDocuments $documents, Signatures $signatures): HttpResponse
    {
        $record = $this->own($request, Proposal::query()
            ->where('client_id', $this->client($request)->id)
            ->visibleToClient(), $proposal);
        $signatures->markViewed($record, $request->user());

        if (! $record->pdf_path) {
            // Sent before PDFs were kept: frozen now, once.
            $documents->freezeProposal($record);
        }

        return response($documents->stored($record->fresh()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'.pdf"',
        ]);
    }

    public function respond(Request $request, int $proposal, Auditor $auditor): RedirectResponse
    {
        $this->mayAsClient($request, 'approve');

        $record = $this->own($request, Proposal::query()
            ->where('client_id', $this->client($request)->id), $proposal);

        if (! $record->canBeAnswered()) {
            return back()->withErrors([
                'response' => $record->hasExpired()
                    ? 'This proposal has passed its validity date. Ask us for a fresh one.'
                    : 'This proposal has already been answered.',
            ]);
        }

        $validated = $this->validatedInput($request, [
            'response' => ['required', Rule::in(['accepted', 'rejected'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // Accepting is signing (CL2): a named person, proved by a one time
        // code, over the PDF that was sent. Only declining happens here.
        if ($validated['response'] === 'accepted') {
            return back()->withErrors(['response' => 'Accept a proposal by signing it.']);
        }

        $record->forceFill([
            'status' => 'rejected',
            'responded_at' => now(),
            'response_note' => $validated['note'] ?? null,
            'responded_ip' => $request->ip(),
        ])->save();

        $record->quotation?->forceFill(['status' => 'rejected'])->save();

        $auditor->action('proposal.rejected', $record, ['by' => 'client'], $record->number);

        return back()->with('success', 'Recorded. We will come back to you.');
    }

    /** @return array<string, mixed> */
    protected function card(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'number' => $proposal->number,
            'title' => $proposal->title,
            'status' => $proposal->status,
            'statusLabel' => str($proposal->status)->title()->toString(),
            'version' => $proposal->version,
            'validUntil' => $proposal->valid_until?->format('j M Y'),
            'expired' => $proposal->hasExpired(),
            'canRespond' => $proposal->canBeAnswered(),
            'total' => $proposal->quotation ? Money::display($proposal->quotation->total) : null,
            'sentAt' => $proposal->sent_at?->format('j M Y'),
        ];
    }

    /* --------------------------------------------- accepting by signing */

    public function signCode(Request $request, int $proposal, Signatures $signatures, ClientDocuments $documents): JsonResponse
    {
        $this->mayAsClient($request, 'approve');
        $record = $this->own($request, Proposal::query()->where('client_id', $this->client($request)->id)->visibleToClient(), $proposal);

        if (! $record->pdf_path) {
            $documents->freezeProposal($record);
        }

        $record = $record->fresh();

        try {
            return response()->json(['sentTo' => $signatures->requestCode($request->user(), $record, 'client', $request), 'sha256' => $record->pdf_sha256]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function sign(Request $request, int $proposal, Signatures $signatures): RedirectResponse
    {
        $this->mayAsClient($request, 'approve');
        $record = $this->own($request, Proposal::query()->where('client_id', $this->client($request)->id)->visibleToClient(), $proposal);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:160'],
            'designation' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:10'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => 'Confirm that you have read it and accept it.',
            'name.required' => 'Type your full name as your signature.',
        ]);

        try {
            $signatures->sign($request->user(), $record, 'client', $validated['name'], $validated['designation'], $validated['code'], $request, $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['code' => $e->getMessage()]);
        }

        return back()->with('success', 'Accepted and signed. Thank you; we will be in touch to start.');
    }

    public function signed(Request $request, int $proposal): HttpResponse
    {
        $record = $this->own($request, Proposal::query()->where('client_id', $this->client($request)->id)->visibleToClient(), $proposal);
        abort_unless($record->signed_pdf_path !== null, 404);

        return response(Storage::disk('private')->get($record->signed_pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($record->number)->replace('/', '-').'-signed.pdf"',
        ]);
    }
}
