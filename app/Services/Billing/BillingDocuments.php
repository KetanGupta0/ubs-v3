<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the invoice and the receipt.
 *
 * Both are rendered from the row rather than from live settings, because the
 * row froze who was billed and by whom at the moment it was issued. The PDF is
 * cached on disk so that a client downloading last year's invoice gets the file
 * that was issued, not a re-render that might differ.
 */
class BillingDocuments
{
    public function invoice(Invoice $invoice): string
    {
        if ($invoice->pdf_path && Storage::disk('private')->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        $invoice->loadMissing('items');

        $pdf = Pdf::loadView('billing.invoice', [
            'invoice' => $invoice,
            'money' => fn (int $paise) => Money::display($paise),
            'words' => Money::words($invoice->total),
        ])->setPaper('a4');

        $path = "invoices/{$invoice->financial_year}/".str($invoice->number)->replace('/', '-')->toString().'.pdf';

        Storage::disk('private')->put($path, $pdf->output());
        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    public function receipt(Transaction $transaction): string
    {
        if ($transaction->receipt_path && Storage::disk('private')->exists($transaction->receipt_path)) {
            return $transaction->receipt_path;
        }

        $transaction->loadMissing(['invoice.items', 'user']);

        $pdf = Pdf::loadView('billing.receipt', [
            'transaction' => $transaction,
            'invoice' => $transaction->invoice,
            'money' => fn (int $paise) => Money::display($paise),
            'words' => Money::words($transaction->amount),
        ])->setPaper('a4');

        $path = "receipts/{$transaction->reference}.pdf";

        Storage::disk('private')->put($path, $pdf->output());
        $transaction->forceFill(['receipt_path' => $path])->save();

        return $path;
    }

    /** Throw away a cached render, for an invoice corrected before it was sent. */
    public function forget(Invoice $invoice): void
    {
        if ($invoice->pdf_path) {
            Storage::disk('private')->delete($invoice->pdf_path);
            $invoice->forceFill(['pdf_path' => null])->save();
        }
    }
}
