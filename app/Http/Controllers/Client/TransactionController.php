<?php

namespace App\Http\Controllers\Client;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Billing\BillingDocuments;
use App\Services\Billing\Invoicer;
use App\Services\Billing\LedgerTable;
use App\Support\Money;
use App\Support\Table\Table;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The client's ledger.
 *
 * Every attempt is here, failures included. A statement that only lists
 * successes cannot answer "I tried three times and it kept failing", which is
 * what somebody asks when they are already annoyed.
 *
 * The same rows drive the screen, the CSV, the spreadsheet and the printed
 * page, because a downloaded statement that disagrees with the screen is worse
 * than no download at all.
 */
class TransactionController extends ClientController
{
    public function index(Request $request, Invoicer $invoicer): Response|HttpResponse
    {
        $table = $this->table($request);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('client/transactions/Index', [
            'table' => $table->toArray($request),
            'summary' => LedgerTable::summaryFor($this->client($request)),
            'invoices' => Invoice::query()
                ->forUser($this->client($request))
                ->latest('id')
                ->take(12)
                ->get()
                ->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'total' => Money::display($invoice->total),
                    'status' => $invoice->status,
                    'issuedAt' => $invoice->issued_at->format('j M Y'),
                    'overdue' => $invoice->isOverdue(),
                ]),
        ]);
    }

    public function invoice(Request $request, int $invoice): Response
    {
        $record = $this->own($request, Invoice::query()
            ->forUser($this->client($request))
            ->with(['items', 'transactions' => fn ($query) => $query->latest('id')]), $invoice);

        return Inertia::render('client/transactions/Invoice', [
            'invoice' => [
                'id' => $record->id,
                'number' => $record->number,
                'status' => $record->status,
                'issuedAt' => $record->issued_at->format('j M Y'),
                'dueOn' => $record->due_on?->format('j M Y'),
                'overdue' => $record->isOverdue(),
                'billedTo' => $record->billed_to,
                'billedFrom' => $record->billed_from,
                'placeOfSupply' => $record->place_of_supply,
                'subtotal' => Money::display($record->subtotal),
                'discount' => Money::display($record->discount),
                'taxBreakup' => collect($record->tax_breakup ?? [])->map(fn (array $part) => [
                    'label' => $part['label'],
                    'rate' => $part['rate'],
                    'amount' => Money::display($part['amount']),
                ]),
                'total' => Money::display($record->total),
                'totalInWords' => $record->totalInWords(),
                'amountPaid' => Money::display($record->amount_paid),
                'balance' => Money::display($record->balance()),
                'terms' => $record->terms,
                'paymentRequestId' => $record->payment_request_id,
                'items' => $record->items->map(fn ($item) => [
                    'description' => $item->description,
                    'hsnSac' => $item->hsn_sac,
                    'quantity' => rtrim(rtrim((string) $item->quantity, '0'), '.'),
                    'unitPrice' => Money::display($item->unit_price),
                    'amount' => Money::display($item->amount),
                ]),
                'payments' => $record->transactions->map(fn (Transaction $transaction) => [
                    'reference' => $transaction->reference,
                    'amount' => $transaction->amountLabel(),
                    'status' => $transaction->status,
                    'at' => $transaction->paid_at?->format('j M Y') ?? $transaction->created_at->format('j M Y'),
                    'receiptable' => $transaction->isSuccessful(),
                ]),
            ],
        ]);
    }

    public function invoicePdf(Request $request, int $invoice, BillingDocuments $documents): StreamedResponse
    {
        $record = $this->own($request, Invoice::query()->forUser($this->client($request)), $invoice);

        $path = $documents->invoice($record);

        return Storage::disk('private')->download(
            $path,
            str($record->number)->replace('/', '-')->toString().'.pdf',
        );
    }

    public function receipt(Request $request, string $reference, BillingDocuments $documents): StreamedResponse
    {
        /** @var Transaction $transaction */
        $transaction = $this->own($request, Transaction::query()
            ->forUser($this->client($request))
            ->successful(), $reference, 'reference');

        $path = $documents->receipt($transaction);

        return Storage::disk('private')->download($path, "receipt-{$transaction->reference}.pdf");
    }

    protected function table(Request $request): Table
    {
        // Shared with the student ledger, so the two screens cannot drift.
        return LedgerTable::for($this->client($request));
    }
}
