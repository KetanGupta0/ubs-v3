<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Billing\CreditNotes;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Issuing credit notes against invoices (CL14). */
class CreditNoteController extends Controller
{
    public function store(Request $request, Invoice $invoice, CreditNotes $creditNotes): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Say why: it is printed on the credit note.',
        ]);

        try {
            $note = $creditNotes->issue($invoice, $request->user(), Money::toPaise($validated['amount']), $validated['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', "Issued credit note {$note->number} for {$note->totalLabel()}. The client's finance contacts have been sent it.");
    }

    public function pdf(CreditNote $creditNote, CreditNotes $creditNotes): HttpResponse
    {
        return response($creditNotes->pdf($creditNote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($creditNote->number)->replace('/', '-').'.pdf"',
        ]);
    }
}
