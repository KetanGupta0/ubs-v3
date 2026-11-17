<?php

use App\Models\ClientMember;
use App\Models\CreditNote;
use App\Models\PaymentRequest;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CreditNoteIssued;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics', 'state' => 'Maharashtra']);
    $this->finance = User::factory()->client()->create();
    ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $this->finance->id, 'role' => 'finance', 'joined_at' => now()]);
    $this->stranger = User::factory()->client()->create();

    $this->bill = function (int $rupees, string $title = 'Advance') {
        $this->actingAs($this->owner)->post('/admin/billing', [
            'user_id' => $this->acme->id, 'title' => $title, 'amount' => $rupees, 'tax_rate' => 18,
        ])->assertRedirect();

        return PaymentRequest::query()->latest('id')->first();
    };
});

it('issues a credit note in its own series, with the invoice\'s tax, and settles the invoice with it', function () {
    $payment = ($this->bill)(100000);
    $invoice = $payment->invoice;
    expect($invoice->tax_breakup[0]['label'])->toBe('IGST');

    $clerk = User::factory()->admin()->create();
    $clerk->permissions()->attach(Permission::query()->where('key', 'billing.view')->get());
    $this->actingAs($clerk)->post("/admin/invoices/{$invoice->id}/credit-notes", ['amount' => 100, 'reason' => 'Agreed discount'])->assertForbidden();

    $this->actingAs($this->owner)->post("/admin/invoices/{$invoice->id}/credit-notes", ['amount' => 20000, 'reason' => 'Agreed discount on the advance'])->assertSessionHas('success');
    $note = CreditNote::query()->sole();

    expect($note->number)->toMatch('~^CN/\d{4}-\d{2}/0001$~')
        ->and($note->subtotal)->toBe(20_000_00)
        ->and($note->tax)->toBe(3_600_00)
        ->and($note->total)->toBe(23_600_00)
        ->and($note->tax_breakup[0]['label'])->toBe('IGST')
        ->and($note->billed_to['name'])->toBe('Acme Logistics')
        ->and($invoice->fresh()->credited_amount)->toBe(23_600_00)
        ->and($invoice->fresh()->balance())->toBe(94_400_00)
        ->and($invoice->fresh()->status)->toBe('partially_paid');
    Notification::assertSentTo([$this->acme, $this->finance], CreditNoteIssued::class);

    $this->actingAs($this->owner)->post("/admin/invoices/{$invoice->id}/credit-notes", ['amount' => 80000.01, 'reason' => 'Too much'])->assertSessionHasErrors('amount');
    $this->actingAs($this->owner)->post("/admin/invoices/{$invoice->id}/credit-notes", ['amount' => 80000, 'reason' => 'Project cancelled'])->assertSessionHas('success');

    expect(CreditNote::query()->latest('id')->first()->number)->toMatch('~/0002$~')
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and($payment->fresh()->status)->toBe('paid')
        ->and(fn () => $note->fresh()->update(['reason' => 'Rewritten']))->toThrow(LogicException::class)
        ->and(fn () => $note->fresh()->delete())->toThrow(LogicException::class);

    $this->actingAs($this->finance)->get("/client/invoices/{$invoice->id}")->assertInertia(fn ($page) => $page->has('invoice.creditNotes', 2));
    expect($this->actingAs($this->finance)->get("/client/credit-notes/{$note->id}/pdf")->assertOk()->getContent())->toStartWith('%PDF');
    $this->actingAs($this->stranger)->get("/client/credit-notes/{$note->id}/pdf")->assertNotFound();
});

it('draws up a statement with a running balance, over any window, on screen and to download', function () {
    $this->travelTo(Carbon::parse('2026-10-01 11:00'));
    $first = ($this->bill)(100000, 'Advance');
    $this->travelTo(Carbon::parse('2026-10-05 11:00'));
    $this->actingAs($this->finance)->post("/client/payments/{$first->id}/tds", ['section' => '194J-professional']);
    $this->actingAs($this->owner)->post("/admin/billing/{$first->id}/offline", ['amount' => 108000, 'method' => 'neft']);

    $this->travelTo(Carbon::parse('2026-11-01 11:00'));
    $second = ($this->bill)(50000, 'Second milestone');
    $this->travelTo(Carbon::parse('2026-11-10 11:00'));
    $this->actingAs($this->owner)->post("/admin/invoices/{$second->invoice->id}/credit-notes", ['amount' => 10000, 'reason' => 'One screen dropped']);

    $this->actingAs($this->finance)->get('/client/statement?from=2026-11-01&to=2026-11-30')
        ->assertInertia(fn ($page) => $page->component('client/Statement')
            ->where('statement.opening', '₹0.00')
            ->has('statement.entries', 2)
            ->where('statement.entries.0.debit', '₹59,000.00')
            ->where('statement.entries.0.balance', '₹59,000.00')
            ->where('statement.entries.1.credit', '₹11,800.00')
            ->where('statement.closing', '₹47,200.00'));

    // The whole financial year: billed, TDS, paid, billed, credited.
    $this->actingAs($this->finance)->get('/client/statement')
        ->assertInertia(fn ($page) => $page->where('statement.from', '2026-04-01')
            ->where('statement.entries', fn ($entries) => collect($entries)->pluck('kind')->all() === ['invoice', 'tds', 'payment', 'invoice', 'credit_note'])
            ->where('statement.closing', '₹47,200.00'));

    expect($this->actingAs($this->finance)->get('/client/statement?format=pdf')->assertOk()->getContent())->toStartWith('%PDF');
    $csv = $this->actingAs($this->finance)->get('/client/statement?format=csv&from=2026-10-01&to=2026-10-31')->assertOk()->streamedContent();
    expect($csv)->toContain('Date,Type,Reference,Description,Debit,Credit,Balance')
        ->toContain('Opening balance')
        ->toContain('tds');

    $this->actingAs($this->owner)->get("/admin/clients/{$this->acme->id}/statement")->assertOk()
        ->assertInertia(fn ($page) => $page->where('statement.closing', '₹47,200.00'));
    $this->actingAs($this->stranger)->get('/client/statement')->assertInertia(fn ($page) => $page->has('statement.entries', 0));
});
