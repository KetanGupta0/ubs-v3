<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Notifications\ProposalSent;
use App\Services\Admin\Auditor;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Writing, pricing and sending a proposal.
 *
 * A draft is invisible to the client until it is sent, and once sent the line
 * items are locked. Editing a price after somebody has read it is how a
 * disagreement starts.
 */
class ProposalController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/proposals/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'all' => Proposal::query()->count(),
                'awaiting' => Proposal::query()->awaitingResponse()->count(),
                'accepted' => Proposal::query()->where('status', 'accepted')->count(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/proposals/Form', [
            'proposal' => null,
            ...$this->options(),
        ]);
    }

    public function store(Request $request, Auditor $auditor, Invoicer $invoicer): RedirectResponse
    {
        $proposal = Proposal::query()->create($this->validated($request) + [
            'created_by' => $request->user()->id,
            'status' => 'draft',
        ]);

        $proposal->quotation()->create([
            'client_id' => $proposal->client_id,
            'status' => 'draft',
        ]);

        $auditor->created($proposal, $proposal->title);

        return redirect()
            ->route('admin.proposals.show', $proposal->id)
            ->with('success', "Proposal {$proposal->number} started. Add the line items next.");
    }

    public function show(Proposal $proposal, Invoicer $invoicer): Response
    {
        $proposal->load(['client:id,name,email', 'project:id,name', 'quotation.items', 'author:id,name']);

        return Inertia::render('admin/proposals/Show', [
            'proposal' => [
                'id' => $proposal->id,
                'number' => $proposal->number,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'body' => $proposal->body,
                'assumptions' => $proposal->assumptions ?? [],
                'deliverables' => $proposal->deliverables ?? [],
                'timeline' => $proposal->timeline,
                'status' => $proposal->status,
                'version' => $proposal->version,
                'validUntil' => $proposal->valid_until?->toDateString(),
                'expired' => $proposal->hasExpired(),
                'sentAt' => $proposal->sent_at?->format('j M Y, g:i a'),
                'respondedAt' => $proposal->responded_at?->format('j M Y, g:i a'),
                'responseNote' => $proposal->response_note,
                'respondedIp' => $proposal->responded_ip,
                'client' => ['id' => $proposal->client_id, 'name' => $proposal->client->name],
                'project' => $proposal->project?->name,
                'author' => $proposal->author?->name,
                'editable' => $proposal->status === 'draft',
            ],
            'quotation' => $proposal->quotation ? [
                'id' => $proposal->quotation->id,
                'number' => $proposal->quotation->number,
                'subtotal' => Money::display($proposal->quotation->subtotal),
                'discount' => Money::display($proposal->quotation->discount),
                'discountValue' => $proposal->quotation->discount / 100,
                'tax' => Money::display($proposal->quotation->tax),
                'total' => Money::display($proposal->quotation->total),
                'notes' => $proposal->quotation->notes,
                'items' => $proposal->quotation->items->map(fn (QuotationItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'hsnSac' => $item->hsn_sac,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'unitPrice' => $item->unit_price / 100,
                    'unitPriceLabel' => Money::display($item->unit_price),
                    'taxRate' => (float) $item->tax_rate,
                    'amount' => Money::display($item->amount),
                ]),
            ] : null,
            'defaultTaxRate' => $invoicer->defaultTaxRate(),
        ]);
    }

    public function update(Request $request, Proposal $proposal, Auditor $auditor): RedirectResponse
    {
        abort_unless($proposal->status === 'draft', 422, 'A sent proposal cannot be edited. Make a new version.');

        $proposal->fill($this->validated($request, $proposal));
        $auditor->updated($proposal, label: $proposal->number);
        $proposal->save();

        return back()->with('success', 'Proposal saved.');
    }

    public function send(Proposal $proposal, Auditor $auditor): RedirectResponse
    {
        abort_unless($proposal->status === 'draft', 422, 'This proposal has already been sent.');

        if ($proposal->quotation && $proposal->quotation->items()->count() === 0) {
            return back()->withErrors(['send' => 'Add at least one line item before sending.']);
        }

        $proposal->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $proposal->quotation?->forceFill(['status' => 'sent'])->save();

        $proposal->client->notify(new ProposalSent($proposal));

        $auditor->action('proposal.sent', $proposal, ['to' => $proposal->client->email], $proposal->number);

        return back()->with('success', "Sent to {$proposal->client->email}.");
    }

    /**
     * Start a new version of a proposal that has already been answered.
     *
     * The old one is kept exactly as it was. Rewriting a rejected proposal in
     * place loses the record of what was actually rejected.
     */
    public function revise(Proposal $proposal, Auditor $auditor): RedirectResponse
    {
        $revision = $proposal->replicate(['number', 'status', 'sent_at', 'responded_at', 'response_note', 'responded_ip']);
        $revision->number = Proposal::nextNumber();
        $revision->version = $proposal->version + 1;
        $revision->status = 'draft';
        $revision->save();

        if ($proposal->quotation) {
            $quotation = $revision->quotation()->create([
                'client_id' => $revision->client_id,
                'discount' => $proposal->quotation->discount,
                'notes' => $proposal->quotation->notes,
                'status' => 'draft',
            ]);

            foreach ($proposal->quotation->items as $item) {
                $copy = $item->replicate(['quotation_id']);
                $copy->quotation_id = $quotation->id;
                $copy->save();
            }

            $quotation->recalculate();
        }

        $auditor->action('proposal.revised', $revision, ['from' => $proposal->number], $revision->number);

        return redirect()
            ->route('admin.proposals.show', $revision->id)
            ->with('success', "Version {$revision->version} started from {$proposal->number}.");
    }

    public function withdraw(Proposal $proposal, Auditor $auditor): RedirectResponse
    {
        $proposal->forceFill(['status' => 'withdrawn'])->save();
        $auditor->action('proposal.withdrawn', $proposal, label: $proposal->number);

        return back()->with('success', 'Withdrawn. The client can no longer respond to it.');
    }

    public function destroy(Proposal $proposal, Auditor $auditor): RedirectResponse
    {
        abort_unless($proposal->status === 'draft', 422, 'A proposal that has been sent is a record. Withdraw it instead.');

        $auditor->deleted($proposal, $proposal->number);
        $proposal->delete();

        return redirect()->route('admin.proposals.index')->with('success', 'Draft removed.');
    }

    /* ------------------------------------------------------- quotation items */

    public function storeItem(Request $request, Proposal $proposal): RedirectResponse
    {
        $quotation = $this->editableQuotation($proposal);

        $quotation->items()->create($this->itemRules($request) + [
            'sort_order' => ($quotation->items()->max('sort_order') ?? -1) + 1,
        ]);

        $quotation->recalculate();

        return back()->with('success', 'Line item added.');
    }

    public function updateItem(Request $request, Proposal $proposal, QuotationItem $item): RedirectResponse
    {
        $quotation = $this->editableQuotation($proposal);
        abort_unless($item->quotation_id === $quotation->id, 404);

        $item->fill($this->itemRules($request))->save();
        $quotation->recalculate();

        return back()->with('success', 'Line item saved.');
    }

    public function destroyItem(Proposal $proposal, QuotationItem $item): RedirectResponse
    {
        $quotation = $this->editableQuotation($proposal);
        abort_unless($item->quotation_id === $quotation->id, 404);

        $item->delete();
        $quotation->recalculate();

        return back()->with('success', 'Line item removed.');
    }

    public function updateQuotation(Request $request, Proposal $proposal): RedirectResponse
    {
        $quotation = $this->editableQuotation($proposal);

        $validated = $this->validatedInput($request, [
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $quotation->forceFill([
            'discount' => Money::toPaise($validated['discount'] ?? 0),
            'notes' => $validated['notes'] ?? null,
        ])->save();

        $quotation->recalculate();

        return back()->with('success', 'Quotation saved.');
    }

    /* --------------------------------------------------------------- helpers */

    protected function editableQuotation(Proposal $proposal): Quotation
    {
        abort_unless($proposal->status === 'draft', 422, 'The price cannot change after the proposal has been sent.');

        return $proposal->quotation ?? $proposal->quotation()->create([
            'client_id' => $proposal->client_id,
            'status' => 'draft',
        ]);
    }

    /** @return array<string, mixed> */
    protected function itemRules(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'description' => ['required', 'string', 'max:255'],
            'hsn_sac' => ['nullable', 'string', 'max:12'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'unit' => ['nullable', 'string', 'max:20'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $validated['unit_price'] = Money::toPaise($validated['unit_price']);

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Proposal $proposal = null): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'body' => ['nullable', 'string', 'max:30000'],
            'assumptions' => ['array'],
            'assumptions.*' => ['string', 'max:300'],
            'deliverables' => ['array'],
            'deliverables.*' => ['string', 'max:300'],
            'timeline' => ['nullable', 'string', 'max:200'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ], [
            'valid_until.after' => 'A validity date in the past is not a validity date.',
        ]);
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return [
            'clients' => User::query()
                ->role(Role::Client)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'value' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email,
                ]),
            'projects' => Project::query()
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Project $project) => [
                    'value' => $project->id,
                    'label' => $project->name,
                    'clientId' => $project->client_id,
                ]),
        ];
    }

    protected function table(): Table
    {
        return Table::for(Proposal::query()->with(['client:id,name', 'quotation:id,proposal_id,total']))
            ->searchable(['number', 'title'])
            ->sortable(['created_at'])
            ->defaultSort('-created_at')
            ->exportName('proposals')
            ->columns([
                Column::make('number', 'Number')->sortable(),
                Column::make('title', 'Title')->sortable(),
                Column::make('client', 'Client'),
                Column::make('total', 'Quoted')->numeric(),
                Column::make('status', 'Status'),
                Column::make('valid_until', 'Valid until')->sortable(),
            ])
            ->filters([
                Filter::multi('status', collect(Proposal::STATUSES)
                    ->map(fn (string $status) => ['value' => $status, 'label' => ucfirst($status)])
                    ->all(), 'Status'),
            ])
            ->transform(fn (Proposal $proposal) => [
                'id' => $proposal->id,
                'number' => $proposal->number,
                'title' => $proposal->title,
                'client' => $proposal->client?->name ?? '—',
                'total' => $proposal->quotation ? Money::display($proposal->quotation->total) : '—',
                'status' => $proposal->status,
                'valid_until' => $proposal->valid_until?->format('j M Y') ?? '—',
                'expired' => $proposal->hasExpired(),
            ]);
    }
}
