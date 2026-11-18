<?php

namespace App\Services\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CreditNoteIssued;
use App\Services\Admin\Auditor;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Credit notes (CL14): reducing an invoice after it was issued, for a
 * discount agreed later, work not delivered, or a mistake. The invoice is
 * never edited; the credit note is a document of its own, numbered in its
 * own series, with tax at the invoice's own rates, and it settles part of
 * the invoice through Invoicer::settle().
 */
class CreditNotes
{
    public function __construct(
        protected Invoicer $invoicer,
        protected InvoiceNumbers $numbers,
        protected Auditor $auditor,
    ) {}

    /** How much taxable value is still open to credit on an invoice. */
    public static function creditable(Invoice $invoice): int
    {
        $credited = (int) CreditNote::query()->where('invoice_id', $invoice->id)->sum('subtotal');

        return max(0, $invoice->taxable() - $credited);
    }

    /** @param  int  $taxable  Paise, before tax. */
    public function issue(Invoice $invoice, User $staff, int $taxable, string $reason): CreditNote
    {
        if (in_array($invoice->status, ['cancelled', 'refunded'], true)) {
            throw new InvalidArgumentException('A cancelled or refunded invoice cannot be credited.');
        }

        $note = DB::transaction(function () use ($invoice, $staff, $taxable, $reason) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($taxable < 1 || $taxable > self::creditable($locked)) {
                throw new InvalidArgumentException('A credit note can be for up to what is left of the invoice\'s taxable value: '.Money::display(self::creditable($locked)).'.');
            }

            // Tax at the invoice's own rates and split, so the credit undoes
            // exactly the tax that was charged.
            $components = collect($locked->tax_breakup ?? [])->map(fn (array $part) => [
                'label' => $part['label'],
                'rate' => $part['rate'],
                'amount' => (int) round($taxable * (float) $part['rate'] / 100),
            ])->values()->all();
            $tax = array_sum(array_column($components, 'amount'));

            $year = $this->numbers->financialYearFor(now());
            $sequence = ((int) CreditNote::query()->where('financial_year', $year)->lockForUpdate()->max('sequence')) + 1;

            $note = CreditNote::query()->create([
                'number' => sprintf('CN/%s/%04d', $year, $sequence),
                'financial_year' => $year,
                'sequence' => $sequence,
                'invoice_id' => $locked->id,
                'user_id' => $locked->user_id,
                'billed_to' => $locked->billed_to,
                'billed_from' => $locked->billed_from,
                'place_of_supply' => $locked->place_of_supply,
                'subtotal' => $taxable,
                'tax_breakup' => $components,
                'tax' => $tax,
                'total' => $taxable + $tax,
                'reason' => trim($reason),
                'issued_at' => now(),
                'issued_by' => $staff->id,
            ]);

            $locked->forceFill(['credited_amount' => $locked->credited_amount + $note->total])->save();
            $this->invoicer->settle($locked);

            return $note;
        });

        $this->auditor->actingAs($staff)->action('credit_note.issued', $invoice, [
            'credit_note' => $note->number,
            'total' => $note->total,
        ], $invoice->number);

        Notification::send($invoice->user->clientRecipients('billing'), new CreditNoteIssued($note));

        return $note;
    }

    /** The PDF, rendered once and kept, like an invoice's. */
    public function pdf(CreditNote $note): string
    {
        if ($note->pdf_path && Storage::disk('private')->exists($note->pdf_path)) {
            return Storage::disk('private')->get($note->pdf_path);
        }

        $output = Pdf::loadView('billing.credit-note', ['note' => $note->loadMissing('invoice')])->setPaper('a4')->output();
        $path = 'billing/credit-notes/'.str($note->number)->replace('/', '-').'.pdf';
        Storage::disk('private')->put($path, $output);
        $note->forceFill(['pdf_path' => $path])->save();

        return $output;
    }
}
