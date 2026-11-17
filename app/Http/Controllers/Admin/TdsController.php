<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TdsDeduction;
use App\Services\Billing\Tds;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The TDS register (CL14): every deduction clients declared on our
 * invoices, the Form 16A for each, and whether it matches Form 26AS.
 */
class TdsController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(TdsDeduction::query()->with('client.profile', 'invoice:id,number,payment_request_id'))
            ->defaultSort('certificate_due_on')
            ->exportName('tds-register')
            ->columns([
                Column::make('client', 'Client'),
                Column::make('invoice', 'Invoice'),
                Column::make('section', 'Section'),
                Column::make('amount', 'TDS')->sortable(),
                Column::make('quarter', 'Quarter')->sortable(),
                Column::make('certificate_due_on', 'Form 16A due')->sortable(),
                Column::make('status', 'Status')->sortable(),
            ])
            ->filters([
                Filter::select('status', collect(TdsDeduction::STATUSES)->mapWithKeys(fn (string $status) => [$status => (new TdsDeduction(['status' => $status]))->statusLabel()])->all(), 'Status')->placeholder('Any'),
            ])
            ->transform(fn (TdsDeduction $deduction) => [
                'id' => $deduction->id,
                'client' => $deduction->client?->organisationName(),
                'invoice' => $deduction->invoice?->number,
                'paymentId' => $deduction->invoice?->payment_request_id,
                'section' => $deduction->sectionLabel(),
                'amount' => $deduction->amountLabel(),
                'quarter' => $deduction->quarter,
                'certificate_due_on' => $deduction->certificate_due_on->format('j M Y'),
                'overdue' => $deduction->status === 'declared' && $deduction->certificate_due_on->isPast(),
                'status' => $deduction->status,
                'statusLabel' => $deduction->statusLabel(),
                'hasCertificate' => $deduction->certificate_path !== null,
                'checkNote' => $deduction->check_note,
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/billing/Tds', ['table' => $table->toArray($request)]);
    }

    public function check(Request $request, TdsDeduction $deduction, Tds $tds): RedirectResponse
    {
        $validated = $this->validatedInput($request, [
            'matches' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $tds->check($deduction, $request->user(), (bool) $validated['matches'], $validated['note']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $validated['matches'] ? 'Marked as matching Form 26AS.' : 'Marked as not matching. The client has been told what to correct.');
    }

    public function certificate(TdsDeduction $deduction): HttpResponse
    {
        abort_unless($deduction->certificate_path !== null && Storage::disk('private')->exists($deduction->certificate_path), 404);

        return response(Storage::disk('private')->get($deduction->certificate_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="form-16a-'.str($deduction->invoice->number)->replace('/', '-').'.pdf"',
        ]);
    }
}
