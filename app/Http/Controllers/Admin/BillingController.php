<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\PaymentRequested;
use App\Services\Admin\Auditor;
use App\Services\Billing\BillingDocuments;
use App\Services\Billing\CreditNotes;
use App\Services\Billing\Invoicer;
use App\Services\Payments\Checkout;
use App\Services\Shop\Orders;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Raising money and recording that it arrived.
 *
 * Raising a request issues its invoice at the same moment, so the client has
 * the document they need to get it approved internally rather than having to
 * pay first and ask for paperwork afterwards.
 */
class BillingController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->requestsTable();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/billing/Index', [
            'table' => $table->toArray($request),
            'totals' => [
                'pending' => Money::display((int) PaymentRequest::query()->pending()->sum('total')),
                'overdue' => Money::display((int) PaymentRequest::query()->overdue()->sum('total')),
                'collectedThisMonth' => Money::display((int) Transaction::query()
                    ->successful()
                    ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                    ->sum('amount')),
                'overdueCount' => PaymentRequest::query()->overdue()->count(),
                // Everything ever received, the old site's payments included,
                // less what was handed back on payments that still stand.
                'collectedTotal' => Money::display(
                    (int) Transaction::query()->successful()->sum('amount')
                    - (int) Refund::query()->where('status', 'processed')
                        ->whereIn('transaction_id', Transaction::query()->successful()->select('id'))
                        ->sum('amount'),
                ),
                'collectedCount' => Transaction::query()->successful()->count(),
            ],
        ]);
    }

    public function create(Request $request, Invoicer $invoicer): Response
    {
        return Inertia::render('admin/billing/Form', [
            'defaultTaxRate' => $invoicer->defaultTaxRate(),
            'people' => User::query()
                ->whereIn('role', [Role::Client->value, Role::Student->value])
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role'])
                ->map(fn (User $user) => [
                    'value' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email.' · '.$user->role->label(),
                ]),
            'projects' => Project::query()
                ->with('milestones:id,project_id,title,payment_amount')
                ->select(['id', 'name', 'client_id', 'po_number'])
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project) => [
                    'value' => $project->id,
                    'label' => $project->name,
                    'clientId' => $project->client_id,
                    'poNumber' => $project->po_number,
                    'milestones' => $project->milestones->map(fn (ProjectMilestone $milestone) => [
                        'value' => $milestone->id,
                        'label' => $milestone->title,
                        'amount' => $milestone->payment_amount ? $milestone->payment_amount / 100 : null,
                    ]),
                ]),
        ]);
    }

    public function store(Request $request, Invoicer $invoicer, Auditor $auditor): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:1'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'milestone_id' => ['nullable', 'integer', Rule::exists('project_milestones', 'id')],
            'po_number' => ['nullable', 'string', 'max:60'],
            'notify' => ['boolean'],
        ]);

        $payable = match (true) {
            filled($validated['milestone_id'] ?? null) => ProjectMilestone::query()->find($validated['milestone_id']),
            filled($validated['project_id'] ?? null) => Project::query()->find($validated['project_id']),
            default => null,
        };

        // Billed to the organisation a client works in (CL8), never to a member,
        // or the organisation would not see what it owes.
        $billed = User::query()->findOrFail($validated['user_id']);

        $payment = PaymentRequest::query()->create([
            'user_id' => $billed->clientAccount()->id,
            'payable_type' => $payable ? $payable::class : null,
            'payable_id' => $payable?->id,
            'title' => $validated['title'],
            // The client's purchase order (CL14): typed here, else the project's.
            'po_number' => filled($validated['po_number'] ?? null)
                ? trim($validated['po_number'])
                : ($payable instanceof ProjectMilestone ? $payable->project?->po_number : ($payable instanceof Project ? $payable->po_number : null)),
            'description' => $validated['description'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'raised_by' => $request->user()->id,
        ]);

        $payment->price(Money::toPaise($validated['amount']), (float) $validated['tax_rate'])->save();

        $invoice = $invoicer->issueFor($payment);

        if ($this->boolInput($request, 'notify', true)) {
            // To finance in a client organisation (CL8), or the person billed.
            Notification::send($payment->user->clientRecipients('billing'), new PaymentRequested($payment, $invoice->number));
        }

        $auditor->action('payment.requested', $payment, [
            'total' => $payment->total,
            'user_id' => $payment->user_id,
        ], $payment->reference);

        return redirect()
            ->route('admin.billing.show', $payment->id)
            ->with('success', "Raised {$payment->reference}. Invoice {$invoice->number} issued.");
    }

    public function show(PaymentRequest $payment, Checkout $checkout): Response
    {
        // role is in the select because the view links to /admin/{role}s/{id}:
        // a constrained eager load returns null for any column left out, not
        // the value, so omitting it is a 500 rather than a missing link.
        $payment->load(['user:id,name,email,mobile,role', 'invoice.items', 'transactions', 'raisedBy:id,name']);

        return Inertia::render('admin/billing/Show', [
            'payment' => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'title' => $payment->title,
                'description' => $payment->description,
                'notes' => $payment->notes,
                'subtotal' => Money::display($payment->subtotal),
                'tax' => Money::display($payment->tax),
                'total' => Money::display($payment->total),
                'totalValue' => $payment->total / 100,
                'status' => $payment->status,
                'dueOn' => $payment->due_on?->format('j M Y'),
                'overdue' => $payment->isOverdue(),
                'raisedAt' => $payment->created_at->format('j M Y'),
                'raisedBy' => $payment->raisedBy?->name,
                'paidAt' => $payment->paid_at?->format('j M Y, g:i a'),
                'user' => [
                    'id' => $payment->user_id,
                    'name' => $payment->user->name,
                    'email' => $payment->user->email,
                    'role' => $payment->user->role->value,
                ],
            ],
            'invoice' => $payment->invoice ? [
                'id' => $payment->invoice->id,
                'number' => $payment->invoice->number,
                'total' => Money::display($payment->invoice->total),
                'paid' => Money::display($payment->invoice->amount_paid),
                'balance' => Money::display($payment->invoice->balance()),
                'balanceValue' => $payment->invoice->balance() / 100,
                'status' => $payment->invoice->status,
                'issuedAt' => $payment->invoice->issued_at->format('j M Y'),
                // What else settled it (CL14).
                'tds' => $payment->invoice->tds_amount ? Money::display($payment->invoice->tds_amount) : null,
                'credited' => $payment->invoice->credited_amount ? Money::display($payment->invoice->credited_amount) : null,
                'creditable' => CreditNotes::creditable($payment->invoice) / 100,
                'creditNotes' => CreditNote::query()->where('invoice_id', $payment->invoice->id)->latest('id')->get()
                    ->map(fn (CreditNote $note) => ['id' => $note->id, 'number' => $note->number, 'total' => $note->totalLabel(), 'reason' => $note->reason, 'issuedAt' => $note->issued_at->format('j M Y')]),
            ] : null,
            'transactions' => $payment->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => $transaction->amountLabel(),
                'status' => $transaction->status,
                'gateway' => $transaction->gateway,
                'method' => $transaction->method,
                'at' => $transaction->created_at->format('j M Y, g:i a'),
                'failureReason' => $transaction->failure_reason,
                'refundable' => $transaction->isSuccessful()
                    && $transaction->refundedAmount() < $transaction->amount,
            ]),
            'gatewayIsLive' => $checkout->gateway()->isLive(),
        ]);
    }

    public function cancel(PaymentRequest $payment, Auditor $auditor): RedirectResponse
    {
        abort_unless($payment->status === 'pending', 422, 'Only a pending request can be cancelled.');

        $payment->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
        $payment->invoice?->forceFill(['status' => 'cancelled'])->save();

        $auditor->action('payment.cancelled', $payment, label: $payment->reference);

        return back()->with('success', 'Cancelled. The client can no longer pay it.');
    }

    /** Record a bank transfer or a cheque, which never touched the gateway. */
    public function recordOffline(Request $request, PaymentRequest $payment, Checkout $checkout, Auditor $auditor): RedirectResponse
    {
        $invoice = $payment->invoice;

        abort_unless($invoice !== null, 422, 'This request has no invoice to settle.');

        $validated = $this->validatedInput($request, [
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $amount = Money::toPaise($validated['amount']);

        if ($amount > $invoice->balance()) {
            return back()->withErrors(['amount' => 'That is more than the balance outstanding.']);
        }

        $transaction = $checkout->recordOffline($invoice, $amount, $validated['method'], $validated['note'] ?? null);

        $auditor->action('payment.recorded_offline', $payment, [
            'amount' => $amount,
            'method' => $validated['method'],
        ], $payment->reference);

        return back()->with('success', "Recorded {$transaction->amountLabel()} against {$invoice->number}.");
    }

    public function refund(Request $request, Transaction $transaction, Auditor $auditor): RedirectResponse
    {
        abort_unless($transaction->isSuccessful(), 422, 'Only a successful payment can be refunded.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $amount = Money::toPaise($validated['amount']);
        $remaining = $transaction->amount - $transaction->refundedAmount();

        if ($amount > $remaining) {
            return back()->withErrors(['amount' => 'That is more than is left to refund on this payment.']);
        }

        $refund = Refund::query()->create([
            'transaction_id' => $transaction->id,
            'amount' => $amount,
            'reason' => $validated['reason'],
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        $auditor->action('payment.refund_requested', $refund, [
            'transaction' => $transaction->reference,
            'amount' => $amount,
        ], $refund->reference);

        return back()->with('success', "Refund {$refund->reference} recorded. Process it with the provider, then mark it done.");
    }

    public function completeRefund(Request $request, Refund $refund, Auditor $auditor, Orders $orders): RedirectResponse
    {
        abort_unless($refund->status === 'pending', 422, 'This refund is already settled.');

        $refund->forceFill(['status' => 'processed', 'processed_at' => now()])->save();

        $transaction = $refund->transaction;

        if ($transaction->refundedAmount() >= $transaction->amount) {
            $transaction->forceFill(['status' => 'refunded'])->save();
            $transaction->invoice?->forceFill(['status' => 'refunded'])->save();
            $transaction->paymentRequest?->forceFill(['status' => 'refunded'])->save();
        }

        $auditor->action('payment.refunded', $refund, label: $refund->reference);

        // An order refunded in full withdraws its licences and plans (§4).
        $orders->refundSettled($transaction->fresh(), $request->user());

        return back()->with('success', 'Refund marked as processed.');
    }

    /* ------------------------------------------------------------- invoices */

    public function invoices(Request $request): Response|HttpResponse
    {
        $table = $this->invoicesTable();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/billing/Invoices', [
            'table' => $table->toArray($request),
            'totals' => [
                'issued' => Money::display((int) Invoice::query()->whereNot('status', 'cancelled')->sum('total')),
                'collected' => Money::display((int) Invoice::query()->sum('amount_paid')),
                'outstanding' => Money::display(
                    (int) Invoice::query()->unpaid()->sum('total') - (int) Invoice::query()->unpaid()->sum('amount_paid'),
                ),
            ],
        ]);
    }

    public function invoicePdf(Invoice $invoice, BillingDocuments $documents): StreamedResponse
    {
        $path = $documents->invoice($invoice);

        return Storage::disk('private')->download(
            $path,
            str($invoice->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    /* -------------------------------------------------------------- tables */

    protected function requestsTable(): Table
    {
        return Table::for(PaymentRequest::query()->with(['user:id,name,role', 'invoice:id,payment_request_id,number']))
            ->searchable(['reference', 'title'])
            ->sortable(['created_at', 'total', 'due_on'])
            ->defaultSort('-created_at')
            ->exportName('payment-requests')
            ->columns([
                Column::make('reference', 'Reference'),
                Column::make('title', 'For'),
                Column::make('person', 'Raised on'),
                Column::make('total', 'Amount')->numeric()
                    ->exportUsing(fn (array $row) => $row['totalValue']),
                Column::make('due_on', 'Due')->sortable(),
                Column::make('status', 'Status'),
                Column::make('invoice', 'Invoice')->hidden(),
            ])
            ->filters([
                Filter::multi('status', collect(PaymentRequest::STATUSES)
                    ->map(fn (string $status) => ['value' => $status, 'label' => ucfirst($status)])
                    ->all(), 'Status'),
                Filter::dateRange('created_at', 'Raised between'),
            ])
            ->transform(fn (PaymentRequest $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'title' => $payment->title,
                'person' => $payment->user?->name ?? '—',
                'total' => Money::display($payment->total),
                'totalValue' => $payment->total / 100,
                'due_on' => $payment->due_on?->format('j M Y') ?? '—',
                'status' => $payment->status,
                'overdue' => $payment->isOverdue(),
                'invoice' => $payment->invoice?->number ?? '—',
            ]);
    }

    protected function invoicesTable(): Table
    {
        return Table::for(Invoice::query()->with('user:id,name'))
            ->searchable(['number'])
            ->sortable(['issued_at', 'total'])
            ->defaultSort('-issued_at')
            ->exportName('invoices')
            ->columns([
                Column::make('number', 'Number'),
                Column::make('person', 'Billed to'),
                Column::make('issued_at', 'Issued')->sortable(),
                Column::make('total', 'Total')->numeric()
                    ->exportUsing(fn (array $row) => $row['totalValue']),
                Column::make('paid', 'Paid')->numeric(),
                Column::make('status', 'Status'),
                Column::make('financial_year', 'Year')->hidden(),
            ])
            ->filters([
                Filter::multi('status', collect(Invoice::STATUSES)
                    ->map(fn (string $status) => [
                        'value' => $status,
                        'label' => str($status)->replace('_', ' ')->title()->toString(),
                    ])->all(), 'Status'),
                Filter::text('financial_year', 'Financial year'),
                Filter::dateRange('issued_at', 'Issued between'),
            ])
            ->transform(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'person' => $invoice->user?->name ?? '—',
                'issued_at' => $invoice->issued_at->format('j M Y'),
                'total' => Money::display($invoice->total),
                'totalValue' => $invoice->total / 100,
                'paid' => Money::display($invoice->amount_paid),
                'status' => $invoice->status,
                'financial_year' => $invoice->financial_year,
                'overdue' => $invoice->isOverdue(),
            ]);
    }
}
