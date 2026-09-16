<?php

namespace App\Http\Controllers\Client;

use App\Models\Proposal;
use App\Models\QuotationItem;
use App\Services\Admin\Auditor;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

    public function show(Request $request, int $proposal): Response
    {
        $record = $this->own($request, Proposal::query()
            ->where('client_id', $this->client($request)->id)
            ->visibleToClient()
            ->with(['quotation.items', 'project:id,name,code', 'author:id,name']), $proposal);

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

    public function respond(Request $request, int $proposal, Auditor $auditor): RedirectResponse
    {
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

        $record->forceFill([
            'status' => $validated['response'],
            'responded_at' => now(),
            'response_note' => $validated['note'] ?? null,
            'responded_ip' => $request->ip(),
        ])->save();

        $record->quotation?->forceFill([
            'status' => $validated['response'] === 'accepted' ? 'accepted' : 'rejected',
        ])->save();

        $auditor->action('proposal.'.$validated['response'], $record, [
            'by' => 'client',
        ], $record->number);

        return back()->with(
            'success',
            $validated['response'] === 'accepted'
                ? 'Thank you. We will be in touch to start.'
                : 'Recorded. We will come back to you.',
        );
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
}
