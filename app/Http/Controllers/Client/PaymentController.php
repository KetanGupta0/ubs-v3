<?php

namespace App\Http\Controllers\Client;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Services\Billing\Invoicer;
use App\Services\Billing\Tds;
use App\Services\Payments\Checkout;
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
                'canPay' => $this->isOwnOrder($request, $record) || $request->user()->canAsClient('pay'),
                'orderId' => $record->payable_type === Order::class ? $record->payable_id : null,
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
            'tds' => $this->tdsFor($request, $record, $invoice),
        ]);
    }

    /* ------------------------------------------------------- TDS (CL14) */

    public function declareTds(Request $request, int $payment, Tds $tds): RedirectResponse
    {
        $this->mayAsClient($request, 'pay');
        $record = $this->own($request, PaymentRequest::query()->forUser($this->client($request)), $payment);

        $validated = $this->validatedInput($request, [
            'section' => ['required', Rule::in(array_keys(Tds::sections()))],
        ], ['section.required' => 'Choose the section you deduct under.', 'section.in' => 'Choose the section you deduct under.']);

        try {
            $deduction = $tds->declare($record, $request->user(), $validated['section']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['section' => $e->getMessage()]);
        }

        return back()->with('success', "Recorded TDS of {$deduction->amountLabel()}. Pay the rest, and send us the Form 16A by {$deduction->certificate_due_on->format('j M Y')}.");
    }

    public function withdrawTds(Request $request, int $payment, Tds $tds): RedirectResponse
    {
        $this->mayAsClient($request, 'pay');
        $record = $this->own($request, PaymentRequest::query()->forUser($this->client($request))->with('invoice.tdsDeduction'), $payment);
        abort_unless($record->invoice?->tdsDeduction !== null, 404);

        try {
            $tds->withdraw($record->invoice->tdsDeduction, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'TDS removed. The full amount is due.');
    }

    public function uploadCertificate(Request $request, int $payment, Tds $tds): RedirectResponse
    {
        $this->mayAsClient($request, 'pay');
        $record = $this->own($request, PaymentRequest::query()->forUser($this->client($request))->with('invoice.tdsDeduction'), $payment);
        abort_unless($record->invoice?->tdsDeduction !== null, 404);

        $this->validatedInput($request, [
            'certificate' => ['required', 'file', 'max:5120', 'mimes:pdf'],
        ], ['certificate.mimes' => 'Upload the Form 16A as a PDF.', 'certificate.max' => 'The PDF can be up to 5 MB.']);

        try {
            $tds->uploadCertificate($record->invoice->tdsDeduction, $request->user(), $request->file('certificate'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['certificate' => $e->getMessage()]);
        }

        return back()->with('success', 'Thank you. We will check it against Form 26AS.');
    }

    public function certificate(Request $request, int $payment): HttpResponse
    {
        $record = $this->own($request, PaymentRequest::query()->forUser($this->client($request))->with('invoice.tdsDeduction'), $payment);
        $path = $record->invoice?->tdsDeduction?->certificate_path;
        abort_unless($path !== null && Storage::disk('private')->exists($path), 404);

        return response(Storage::disk('private')->get($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="form-16a-'.str($record->invoice->number)->replace('/', '-').'.pdf"',
        ]);
    }

    /** @return array<string, mixed>|null What the page shows about TDS on this invoice. */
    protected function tdsFor(Request $request, PaymentRequest $record, ?Invoice $invoice): ?array
    {
        if (! $invoice || ! $record->user?->isClient()) {
            return null;
        }

        $mayPay = $request->user()->canAsClient('pay');
        $deduction = $invoice->tdsDeduction;

        return [
            'deduction' => $deduction ? [
                'section' => $deduction->sectionLabel(),
                'rate' => $deduction->rate,
                'amount' => $deduction->amountLabel(),
                'quarter' => $deduction->quarter,
                'dueOn' => $deduction->certificate_due_on->format('j M Y'),
                'status' => $deduction->status,
                'statusLabel' => $deduction->statusLabel(),
                'checkNote' => $deduction->check_note,
                'hasCertificate' => $deduction->certificate_path !== null,
                'canWithdraw' => $mayPay && $deduction->status === 'declared' && $invoice->amount_paid === 0,
                'canUpload' => $mayPay && in_array($deduction->status, ['declared', 'mismatch'], true),
            ] : null,
            'sections' => ! $deduction && $mayPay && $record->isPayable() && $invoice->amount_paid === 0
                ? collect(Tds::sections())->map(fn (array $section, string $key) => [
                    'value' => $key,
                    'label' => "{$section['label']}, {$section['rate']}%",
                    'amount' => Money::display(Tds::amountFor($invoice, $key)),
                    'net' => Money::display(max(0, $invoice->balance() - Tds::amountFor($invoice, $key))),
                ])->values()
                : [],
        ];
    }

    /** Open checkout and hand the browser what the provider needs. */
    public function begin(Request $request, int $payment, Checkout $checkout): JsonResponse
    {
        $record = $this->own($request, PaymentRequest::query()
            ->forUser($this->client($request)), $payment);

        // Whoever placed an order pays for it with their own card (§4); paying
        // the organisation's invoices is finance's, with two-step sign in.
        if (! $this->isOwnOrder($request, $record)) {
            $this->mayAsClient($request, 'pay');
        }

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

        $payable = $transaction->paymentRequest?->payable;

        if ($payable instanceof Order) {
            return redirect("/client/orders/{$payable->id}")
                ->with('success', 'Payment received. Thank you. What you bought is below.');
        }

        return redirect()
            ->route('client.transactions.index')
            ->with('success', 'Payment received. Your receipt is ready to download.');
    }

    /** An order this person placed, waiting for their payment. */
    protected function isOwnOrder(Request $request, PaymentRequest $record): bool
    {
        return $record->payable_type === Order::class
            && $record->payable instanceof Order
            && $record->payable->user_id === $request->user()->id;
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
