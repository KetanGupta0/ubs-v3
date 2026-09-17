<?php

namespace App\Http\Controllers\Student;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Transaction;
use App\Services\Billing\BillingDocuments;
use App\Services\Billing\Invoicer;
use App\Services\Billing\LedgerTable;
use App\Services\Lms\Enroller;
use App\Services\Payments\Checkout;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Course fees and the student's ledger.
 *
 * The same machinery as the client side, because it is the same money: one
 * ledger, one invoice numbering sequence, one receipt renderer. A second
 * payment path for students would be a second place for a rounding bug.
 */
class BillingController extends StudentController
{
    public function index(Request $request): Response|HttpResponse
    {
        $student = $this->student($request);
        $table = LedgerTable::for($student);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('student/payments/Index', [
            'table' => $table->toArray($request),
            'summary' => LedgerTable::summaryFor($student),
            'pending' => PaymentRequest::query()
                ->forUser($student)
                ->pending()
                ->orderBy('due_on')
                ->get()
                ->map(fn (PaymentRequest $payment) => [
                    'id' => $payment->id,
                    'reference' => $payment->reference,
                    'title' => $payment->title,
                    'total' => Money::display($payment->total),
                    'dueOn' => $payment->due_on?->format('j M Y'),
                    'overdue' => $payment->isOverdue(),
                ]),
            'invoices' => Invoice::query()
                ->forUser($student)
                ->latest('id')
                ->take(12)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'total' => Money::display($invoice->total),
                    'status' => $invoice->status,
                    'issuedAt' => $invoice->issued_at->format('j M Y'),
                ]),
        ]);
    }

    public function show(Request $request, int $payment, Invoicer $invoicer, Checkout $checkout): Response
    {
        $record = $this->ownRequest($request, $payment);

        $invoice = $record->isPayable() ? $invoicer->issueFor($record) : $record->invoice;

        return Inertia::render('student/payments/Show', [
            'payment' => [
                'id' => $record->id,
                'reference' => $record->reference,
                'title' => $record->title,
                'description' => $record->description,
                'subtotal' => Money::display($record->subtotal),
                'tax' => Money::display($record->tax),
                'taxRate' => (float) $record->tax_rate,
                'total' => Money::display($record->total),
                'status' => $record->status,
                'statusLabel' => str($record->status)->title()->toString(),
                'dueOn' => $record->due_on?->format('j M Y'),
                'overdue' => $record->isOverdue(),
                'payable' => $record->isPayable(),
            ],
            'invoice' => $invoice ? [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issuedAt' => $invoice->issued_at->format('j M Y'),
                'total' => Money::display($invoice->total),
            ] : null,
            'gateway' => [
                'name' => $checkout->gateway()->name(),
                'live' => $checkout->gateway()->isLive(),
            ],
        ]);
    }

    public function begin(Request $request, int $payment, Checkout $checkout): JsonResponse
    {
        $record = $this->ownRequest($request, $payment);
        $started = $checkout->begin($record, $this->student($request));

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

    public function confirm(Request $request, string $reference, Checkout $checkout, Enroller $enroller): RedirectResponse
    {
        $transaction = Transaction::query()
            ->forUser($this->student($request))
            ->where('reference', $reference)
            ->firstOrFail();

        $result = $checkout->settle($transaction, $request->all());

        if (! $result->successful) {
            return back()->with('error', $result->failureReason ?? 'The payment did not go through.');
        }

        // A paid course fee opens the lessons that were waiting on it, without
        // anybody having to remember to do it.
        $this->openCourseFor($transaction, $enroller);

        return redirect()
            ->route('student.payments.index')
            ->with('success', 'Paid. Your course is fully open.');
    }

    public function invoicePdf(Request $request, int $invoice, BillingDocuments $documents): StreamedResponse
    {
        /** @var Invoice $record */
        $record = Invoice::query()->forUser($this->student($request))->findOrFail($invoice);

        $path = $documents->invoice($record);

        return Storage::disk('private')->download(
            $path,
            str($record->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    public function receipt(Request $request, string $reference, BillingDocuments $documents): StreamedResponse
    {
        /** @var Transaction $transaction */
        $transaction = Transaction::query()
            ->forUser($this->student($request))
            ->successful()
            ->where('reference', $reference)
            ->firstOrFail();

        $path = $documents->receipt($transaction);

        return Storage::disk('private')->download($path, "receipt-{$transaction->reference}.pdf");
    }

    protected function ownRequest(Request $request, int $payment): PaymentRequest
    {
        return PaymentRequest::query()
            ->forUser($this->student($request))
            ->with('invoice')
            ->findOrFail($payment);
    }

    protected function openCourseFor(Transaction $transaction, Enroller $enroller): void
    {
        $enrolment = Enrollment::query()
            ->where('payment_request_id', $transaction->payment_request_id)
            ->first();

        if ($enrolment && ! $enrolment->has_paid) {
            $enroller->markPaid($enrolment);
        }
    }
}
