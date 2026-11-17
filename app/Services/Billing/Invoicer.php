<?php

namespace App\Services\Billing;

use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Issues invoices and records what was paid against them.
 *
 * An invoice is issued when a payment request is raised, not when it is paid,
 * so the client has the document they need in order to get it approved and paid
 * internally. Marking it paid is a separate step driven by the gateway.
 */
class Invoicer
{
    public function __construct(protected InvoiceNumbers $numbers) {}

    public function issueFor(PaymentRequest $request): Invoice
    {
        if ($existing = $request->invoice()->first()) {
            return $existing;
        }

        $user = $request->user;
        $billedTo = $this->billedTo($user);
        $placeOfSupply = $billedTo['state'] ?? null;

        $breakup = $this->gst()->for($request->subtotal, (float) $request->tax_rate, $placeOfSupply);

        return DB::transaction(function () use ($request, $billedTo, $placeOfSupply, $breakup) {
            $claimed = $this->numbers->next();

            $invoice = Invoice::query()->create([
                'user_id' => $request->user_id,
                'payment_request_id' => $request->id,
                'number' => $claimed['number'],
                // The client's purchase order, fixed with the invoice (CL14).
                'po_number' => $request->po_number,
                'financial_year' => $claimed['financialYear'],
                'sequence' => $claimed['sequence'],
                'billed_to' => $billedTo,
                'billed_from' => $this->billedFrom(),
                'subtotal' => $request->subtotal,
                'tax_breakup' => $breakup['components'],
                'tax' => $breakup['total'],
                'total' => $request->subtotal + $breakup['total'],
                'currency' => $request->currency,
                'place_of_supply' => $placeOfSupply,
                'status' => 'issued',
                'issued_at' => now(),
                'due_on' => $request->due_on,
                'terms' => Setting::get('invoicing.terms'),
            ]);

            $invoice->items()->create([
                'description' => $request->title,
                'quantity' => 1,
                'unit_price' => $request->subtotal,
                'tax_rate' => $request->tax_rate,
                'amount' => $request->subtotal,
            ]);

            // The request's own total is refreshed from the invoice, because the
            // tax split can round differently from a flat percentage and the two
            // screens must not show different numbers.
            $request->forceFill([
                'tax' => $breakup['total'],
                'total' => $invoice->total,
            ])->save();

            return $invoice;
        });
    }

    /** Record a successful payment against its invoice. */
    public function recordPayment(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $invoice = $transaction->invoice;

            if ($invoice) {
                $invoice->forceFill(['amount_paid' => (int) $invoice->transactions()->successful()->sum('amount')])->save();
                $this->settle($invoice);

                return;
            }

            // A request with no invoice yet: settled by its own payments.
            $request = $transaction->paymentRequest;

            if ($request && $request->status === 'pending' && (int) $request->transactions()->successful()->sum('amount') >= $request->total) {
                $this->markRequestPaid($request);
            }
        });
    }

    /**
     * Work out where an invoice stands from everything that settles it: what
     * was paid, the TDS the client deducted, and credit notes (CL14). When
     * nothing is left, its payment request is paid, here and nowhere else.
     */
    public function settle(Invoice $invoice): void
    {
        if (in_array($invoice->status, ['cancelled', 'refunded'], true)) {
            return;
        }

        $covered = $invoice->amount_paid + $invoice->tds_amount + $invoice->credited_amount;

        $invoice->forceFill([
            'status' => match (true) {
                $covered >= $invoice->total => 'paid',
                $covered > 0 => 'partially_paid',
                default => 'issued',
            },
        ])->save();

        $request = $invoice->paymentRequest;

        if ($request && $request->status === 'pending' && $invoice->status === 'paid') {
            $this->markRequestPaid($request);
        }
    }

    protected function markRequestPaid(PaymentRequest $request): void
    {
        $request->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        // A course fee settled in full opens the lessons that were waiting
        // on it. Here, rather than in whichever screen took the money, so a
        // bank transfer recorded by hand opens the course exactly as an
        // online payment does.
        Enrollment::query()
            ->where('payment_request_id', $request->id)
            ->where('has_paid', false)
            ->get()
            ->each(fn (Enrollment $enrolment) => $enrolment->forceFill(['has_paid' => true])->save());
    }

    /** @return array<string, mixed> */
    public function billedTo(User $user): array
    {
        $profile = $user->profile;

        return [
            'name' => $profile?->company ?: $user->name,
            'contact' => $user->name,
            'email' => $user->email,
            'mobile' => $user->mobile,
            'address' => $profile?->address_line_1,
            'city' => $profile?->city,
            'state' => $profile?->state,
            'postal_code' => $profile?->postal_code,
            'gstin' => $profile?->gstin,
        ];
    }

    /** @return array<string, mixed> */
    public function billedFrom(): array
    {
        return [
            'name' => Setting::get('company.legal_name', config('company.legal_name')),
            'trading_name' => Setting::get('company.name', config('company.name')),
            'email' => Setting::get('company.email', config('company.email')),
            'phone' => Setting::get('company.phone', config('company.phone')),
            'address' => Setting::get('company.address', config('company.address')),
            'city' => Setting::get('company.city'),
            'state' => Setting::get('company.state'),
            'postal_code' => Setting::get('company.postal_code'),
            'gstin' => Setting::get('company.gstin', config('company.gstin')),
            'cin' => Setting::get('company.cin', config('company.cin')),
            'pan' => Setting::get('company.pan', config('company.pan')),
        ];
    }

    public function gst(): GstBreakup
    {
        return new GstBreakup((string) Setting::get('company.state', ''));
    }

    /** The tax rate an administrator did not override. */
    public function defaultTaxRate(): float
    {
        return (float) Setting::get('invoicing.default_tax_rate', 18);
    }

    public function summaryFor(User $user): array
    {
        return [
            'billed' => Money::display((int) $user->invoices()->whereNot('status', 'cancelled')->sum('total')),
            'paid' => Money::display((int) $user->transactions()->successful()->sum('amount')),
            'due' => Money::display((int) $user->paymentRequests()->pending()->sum('total')),
        ];
    }
}
