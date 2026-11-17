<?php

namespace App\Http\Controllers\Client;

use App\Models\CreditNote;
use App\Services\Billing\CreditNotes;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The organisation's own credit notes, as PDFs (CL14). */
class CreditNoteController extends ClientController
{
    public function pdf(Request $request, int $creditNote, CreditNotes $creditNotes): HttpResponse
    {
        $note = $this->own($request, CreditNote::query()->forUser($this->client($request)), $creditNote);

        return response($creditNotes->pdf($note), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str($note->number)->replace('/', '-').'.pdf"',
        ]);
    }
}
