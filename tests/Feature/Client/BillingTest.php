<?php

use App\Models\Invoice;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Billing\Invoicer;
use App\Services\Payments\Checkout;
use App\Support\Money;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->owner = User::factory()->owner()->create();
    $this->client = User::factory()->client()->create();
    $this->client->profile()->create(['state' => 'Madhya Pradesh']);

    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.prefix', 'UBS', 'invoicing');
});

function requestFor(User $user, int $rupees = 100000, float $rate = 18): PaymentRequest
{
    $request = PaymentRequest::query()->create([
        'user_id' => $user->id,
        'title' => 'Milestone two',
    ]);

    $request->price(Money::toPaise($rupees), $rate)->save();

    return $request;
}

/* -------------------------------------------------------------- invoices */

it('issues an invoice when the request is raised, not when it is paid', function () {
    $this->actingAs($this->owner)->post('/admin/billing', [
        'user_id' => $this->client->id,
        'title' => 'Milestone two',
        'amount' => 100000,
        'tax_rate' => 18,
        'notify' => false,
    ])->assertRedirect();

    $invoice = Invoice::query()->sole();

    expect($invoice->status)->toBe('issued')
        ->and($invoice->subtotal)->toBe(10000000)
        ->and($invoice->total)->toBe(11800000);
});

it('splits tax into CGST and SGST inside our own state', function () {
    $invoice = app(Invoicer::class)->issueFor(requestFor($this->client));

    expect(collect($invoice->tax_breakup)->pluck('label')->all())->toBe(['CGST', 'SGST'])
        ->and(collect($invoice->tax_breakup)->sum('amount'))->toBe($invoice->tax);
});

it('charges IGST when the client is in another state', function () {
    $this->client->profile->forceFill(['state' => 'Karnataka'])->save();

    $invoice = app(Invoicer::class)->issueFor(requestFor($this->client->fresh()));

    expect(collect($invoice->tax_breakup)->pluck('label')->all())->toBe(['IGST']);
});

it('never loses a paisa in the tax split', function () {
    // An odd number of paise cannot be halved evenly, so the two halves have to
    // add back up to the total rather than to one less than it.
    $invoice = app(Invoicer::class)->issueFor(requestFor($this->client, 1000.55));

    expect(collect($invoice->tax_breakup)->sum('amount'))->toBe($invoice->tax)
        ->and($invoice->subtotal + $invoice->tax)->toBe($invoice->total);
});

it('numbers invoices in one unbroken sequence', function () {
    $numbers = collect(range(1, 3))
        ->map(fn () => app(Invoicer::class)->issueFor(requestFor($this->client))->number);

    expect($numbers->unique())->toHaveCount(3)
        ->and($numbers->first())->toEndWith('0001')
        ->and($numbers->last())->toEndWith('0003');
});

it('freezes who was billed into the invoice', function () {
    $invoice = app(Invoicer::class)->issueFor(requestFor($this->client));

    $this->client->forceFill(['name' => 'Renamed Later'])->save();

    // An invoice that re-renders from live data is not a record of anything.
    expect($invoice->fresh()->billed_to['name'])->not->toBe('Renamed Later');
});

it('does not issue a second invoice for the same request', function () {
    $request = requestFor($this->client);

    $first = app(Invoicer::class)->issueFor($request);
    $second = app(Invoicer::class)->issueFor($request->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::query()->count())->toBe(1);
});

/* -------------------------------------------------------------- payments */

it('takes the amount from the invoice, never from the request body', function () {
    $request = requestFor($this->client, 100000);

    $started = app(Checkout::class)->begin($request, $this->client);

    // A client naming their own amount could settle a lakh rupee invoice for
    // one rupee, which is the single easiest mistake to make here.
    expect($started['transaction']->amount)->toBe(11800000);
});

it('will not let one client pay another client’s request', function () {
    $stranger = User::factory()->client()->create();
    $request = requestFor($stranger);

    expect(fn () => app(Checkout::class)->begin($request, $this->client))
        ->toThrow(HttpException::class);
});

it('reuses an open attempt rather than leaving a trail of orders', function () {
    $request = requestFor($this->client);
    $checkout = app(Checkout::class);

    $first = $checkout->begin($request, $this->client)['transaction'];
    $second = $checkout->begin($request->fresh(), $this->client)['transaction'];

    expect($second->id)->toBe($first->id)
        ->and(Transaction::query()->count())->toBe(1);
});

it('marks the invoice and the request paid once the money is in', function () {
    $request = requestFor($this->client);
    $checkout = app(Checkout::class);

    $transaction = $checkout->begin($request, $this->client)['transaction'];
    $checkout->settle($transaction, ['outcome' => 'success']);

    expect($transaction->fresh()->status)->toBe('successful')
        ->and($transaction->fresh()->invoice->status)->toBe('paid')
        ->and($request->fresh()->status)->toBe('paid');
});

it('keeps a failed attempt on the ledger', function () {
    $request = requestFor($this->client);
    $checkout = app(Checkout::class);

    $transaction = $checkout->begin($request, $this->client)['transaction'];
    $result = $checkout->settle($transaction, ['outcome' => 'failure']);

    expect($result->successful)->toBeFalse()
        ->and($transaction->fresh()->status)->toBe('failed')
        ->and($request->fresh()->status)->toBe('pending')
        ->and(Transaction::query()->count())->toBe(1);
});

it('will not settle the same payment twice', function () {
    $request = requestFor($this->client);
    $checkout = app(Checkout::class);

    $transaction = $checkout->begin($request, $this->client)['transaction'];
    $checkout->settle($transaction, ['outcome' => 'success']);
    $paidAt = $transaction->fresh()->paid_at;

    $checkout->settle($transaction->fresh(), ['outcome' => 'success']);

    expect($transaction->fresh()->paid_at->toIso8601String())->toBe($paidAt->toIso8601String())
        ->and($transaction->fresh()->invoice->amount_paid)->toBe($transaction->amount);
});

it('records a bank transfer against the invoice', function () {
    $request = requestFor($this->client);
    $invoice = app(Invoicer::class)->issueFor($request);

    $this->actingAs($this->owner)->post("/admin/billing/{$request->id}/offline", [
        'amount' => $invoice->total / 100,
        'method' => 'bank transfer',
        'note' => 'UTR 123456',
    ])->assertRedirect();

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($request->fresh()->status)->toBe('paid');
});

it('refuses an offline payment larger than the balance', function () {
    $request = requestFor($this->client);
    $invoice = app(Invoicer::class)->issueFor($request);

    $this->actingAs($this->owner)->post("/admin/billing/{$request->id}/offline", [
        'amount' => ($invoice->total / 100) + 1000,
        'method' => 'cheque',
    ])->assertSessionHasErrors('amount');

    expect($invoice->fresh()->amount_paid)->toBe(0);
});

it('shows a part payment as partially paid', function () {
    $request = requestFor($this->client);
    $invoice = app(Invoicer::class)->issueFor($request);

    $this->actingAs($this->owner)->post("/admin/billing/{$request->id}/offline", [
        'amount' => 50000,
        'method' => 'bank transfer',
    ]);

    expect($invoice->fresh()->status)->toBe('partially_paid')
        ->and($request->fresh()->status)->toBe('pending');
});

/* --------------------------------------------------------------- refunds */

it('will not refund more than was taken', function () {
    $request = requestFor($this->client);
    $checkout = app(Checkout::class);
    $transaction = $checkout->begin($request, $this->client)['transaction'];
    $checkout->settle($transaction, ['outcome' => 'success']);

    $this->actingAs($this->owner)->post("/admin/transactions/{$transaction->id}/refund", [
        'amount' => ($transaction->amount / 100) + 1,
        'reason' => 'Trying it on',
    ])->assertSessionHasErrors('amount');
});

/* ---------------------------------------------------------- the ledger */

it('lets a client download a receipt only for their own payment', function () {
    $stranger = User::factory()->client()->create();
    $request = requestFor($stranger);
    $checkout = app(Checkout::class);
    $transaction = $checkout->begin($request, $stranger)['transaction'];
    $checkout->settle($transaction, ['outcome' => 'success']);

    $this->actingAs($this->client)->get("/client/receipts/{$transaction->reference}")->assertNotFound();
    $this->actingAs($stranger)->get("/client/receipts/{$transaction->reference}")->assertOk();
});

it('renders an invoice PDF that carries its own number', function () {
    $invoice = app(Invoicer::class)->issueFor(requestFor($this->client));

    $response = $this->actingAs($this->client)->get("/client/invoices/{$invoice->id}/pdf");

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    expect($invoice->fresh()->pdf_path)->not->toBeNull();
});
