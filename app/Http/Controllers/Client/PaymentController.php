<?php

namespace App\Http\Controllers\Client;

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Services\Billing\Invoicer;
use App\Services\Payments\Checkout;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paying for things.
 *
 * The amount always comes from the invoice on the server. A checkout that reads
 * its amount from the request body lets a client pay a lakh rupee invoice with
 * one rupee, and it is the single easiest mistake to make here.
 */
class PaymentController extends ClientController
{
    public function index(Request $request, Invoicer $invoicer): Response
    {
        $client = $this->client($request);

        return Inertia::render('client/payments/Index', [
            'pending' => PaymentRequest::query()
                ->forUser($client)
                ->pending()
                ->orderBy('due_on')
                ->get()
                ->map(fn (PaymentRequest $payment) => $this->card($payment)),

            'recent' => PaymentRequest::query()
                ->forUser($client)
                ->whereNot('status', 'pending')
                ->latest('id')
                ->take(10)
                ->get()
                ->map(fn (PaymentRequest $payment) => $this->card($payment)),

            'summary' => $invoicer->summaryFor($client),
        ]);
    }

    public function show(Request $request, int $payment, Invoicer $invoicer, Checkout $checkout): Response
    {
        $record = $this->own($request, PaymentRequest::query()
            ->forUser($this->client($request))
            ->with('invoice'), $payment);

        // Issuing on open rather than on pay means the client has the document
        // they need in order to get it approved internally before paying.
        $invoice = $record->isPayable() ? $invoicer->issueFor($record) : $record->invoice;

        return Inertia::render('client/payments/Show', [
            'payment' => [
                ...$this->card($record),
                'description' => $record->description,
                'notes' => $record->notes,
                'subtotal' => Money::display($record->subtotal),
                'tax' => Money::display($record->tax),
                'taxRate' => (float) $record->tax_rate,
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issuedAt' => $invoice->issued_at->format('j M Y'),
                'total' => Money::display($invoice->total),
                'balance' => Money::display($invoice->balance()),
                'status' => $invoice->status,
            ] : null,
            'gateway' => [
                'name' => $checkout->gateway()->name(),
                'live' => $checkout->gateway()->isLive(),
            ],
        ]);
    }

    /** Open checkout and hand the browser what the provider needs. */
    public function begin(Request $request, int $payment, Checkout $checkout): JsonResponse
    {
        $record = $this->own($request, PaymentRequest::query()
            ->forUser($this->client($request)), $payment);

        $started = $checkout->begin($record, $request->user());

        return response()->json([
            'transaction' => $started['transaction']->reference,
            'order' => $started['order']->toArray(),
            'name' => config('company.name'),
            'description' => $record->title,
            'prefill' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'contact' => $request->user()->mobile,
            ],
        ]);
    }

    /** Settle what the provider's widget reported. */
    public function confirm(Request $request, string $reference, Checkout $checkout): RedirectResponse
    {
        $transaction = $this->own($request, Transaction::query()
            ->forUser($this->client($request)), $reference, 'reference');

        $result = $checkout->settle($transaction, $request->all());

        if (! $result->successful) {
            return back()->with('error', $result->failureReason ?? 'The payment did not go through.');
        }

        return redirect()
            ->route('client.transactions.index')
            ->with('success', 'Payment received. Your receipt is ready to download.');
    }

    /** @return array<string, mixed> */
    protected function card(PaymentRequest $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'title' => $payment->title,
            'total' => Money::display($payment->total),
            'status' => $payment->status,
            'statusLabel' => str($payment->status)->title()->toString(),
            'dueOn' => $payment->due_on?->format('j M Y'),
            'overdue' => $payment->isOverdue(),
            'payable' => $payment->isPayable(),
            'raisedAt' => $payment->created_at->format('j M Y'),
            'paidAt' => $payment->paid_at?->format('j M Y'),
        ];
    }
}
