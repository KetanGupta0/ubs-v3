<?php

use App\Models\ClientMember;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Models\Setting;
use App\Models\TdsDeduction;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\TdsNotice;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
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
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->finance = $member('finance');
    $this->technical = $member('technical');

    $this->project = Project::query()->create(['client_id' => $this->acme->id, 'name' => 'Warehouse system', 'status' => 'in_progress', 'po_number' => 'PO-ACME-7781']);

    // ₹1,00,000 before tax, 18% GST: ₹1,18,000.
    $this->bill = function (array $overrides = []) {
        $this->actingAs($this->owner)->post('/admin/billing', [
            'user_id' => $this->acme->id, 'title' => 'Advance', 'amount' => 100000, 'tax_rate' => 18,
            'project_id' => $this->project->id, 'due_on' => today()->addDays(10)->toDateString(), ...$overrides,
        ])->assertRedirect();

        return PaymentRequest::query()->latest('id')->first();
    };
});

it('prints the client\'s PO number, from the project or as typed', function () {
    $fromProject = ($this->bill)();
    $typed = ($this->bill)(['po_number' => 'PO-OVERRIDE-1']);

    expect($fromProject->invoice->po_number)->toBe('PO-ACME-7781')
        ->and($typed->invoice->po_number)->toBe('PO-OVERRIDE-1');

    $this->actingAs($this->finance)->get("/client/invoices/{$fromProject->invoice->id}")
        ->assertInertia(fn ($page) => $page->where('invoice.poNumber', 'PO-ACME-7781'));
});

it('lets finance keep the billing details, checking the GSTIN against the state, for invoices from now on', function () {
    $before = ($this->bill)();

    $details = ['company' => 'Acme Logistics Private Limited', 'gstin' => '27ABCDE1234F1Z5', 'state' => 'Maharashtra', 'city' => 'Pune', 'postal_code' => '411001'];

    $this->actingAs($this->technical)->put('/client/billing-details', $details)->assertForbidden();
    $this->actingAs($this->finance)->put('/client/billing-details', [...$details, 'gstin' => 'ABC'])->assertSessionHasErrors('gstin');
    $this->actingAs($this->finance)->put('/client/billing-details', [...$details, 'state' => 'Gujarat'])
        ->assertSessionHasErrors(['gstin' => 'This GSTIN is registered in Maharashtra. Choose that state, or check the number.']);
    $this->actingAs($this->finance)->put('/client/billing-details', [...$details, 'state' => 'MH'])->assertSessionHasErrors('state');
    $this->actingAs($this->finance)->put('/client/billing-details', [...$details, 'gstin' => '27abcde1234f1z5'])->assertSessionHas('success');

    expect($this->acme->fresh()->profile->gstin)->toBe('27ABCDE1234F1Z5');

    $after = ($this->bill)();
    expect($after->invoice->billed_to['gstin'])->toBe('27ABCDE1234F1Z5')
        ->and($after->invoice->billed_to['name'])->toBe('Acme Logistics Private Limited')
        ->and($before->invoice->fresh()->billed_to['gstin'])->toBeNull();
});

it('takes TDS before payment, so the client pays the net and the invoice settles', function () {
    $payment = ($this->bill)();
    $invoice = $payment->invoice;
    $base = "/client/payments/{$payment->id}";

    $this->actingAs($this->finance)->get($base)
        ->assertInertia(fn ($page) => $page->has('tds.sections', 3)->where('tds.sections.0.amount', '₹10,000.00')->where('tds.sections.0.net', '₹1,08,000.00'));

    // A checkout started before the TDS is not reused for the new amount.
    $this->actingAs($this->finance)->postJson("{$base}/checkout")->assertOk();
    expect(Transaction::query()->sole()->amount)->toBe(1_18_000_00);

    $this->actingAs($this->technical)->post("{$base}/tds", ['section' => '194J-professional'])->assertForbidden();
    $this->actingAs($this->finance)->post("{$base}/tds", ['section' => '194X'])->assertSessionHasErrors('section');
    $this->actingAs($this->finance)->post("{$base}/tds", ['section' => '194J-professional'])->assertSessionHas('success');

    $deduction = TdsDeduction::query()->sole();
    $quarter = TdsDeduction::quarterOf(now());
    expect($deduction->amount)->toBe(10_000_00)
        ->and($deduction->base)->toBe(1_00_000_00)
        ->and($deduction->quarter)->toBe($quarter['quarter'])
        ->and($invoice->fresh()->balance())->toBe(1_08_000_00)
        ->and($invoice->fresh()->status)->toBe('partially_paid');
    $this->actingAs($this->finance)->post("{$base}/tds", ['section' => '194C'])->assertSessionHasErrors('section');

    $this->actingAs($this->finance)->postJson("{$base}/checkout")->assertOk();
    expect(Transaction::query()->latest('id')->first()->amount)->toBe(1_08_000_00);

    // The net, paid by bank transfer, settles the invoice and the request.
    $this->actingAs($this->owner)->post("/admin/billing/{$payment->id}/offline", ['amount' => 108000, 'method' => 'neft'])->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('paid')
        ->and($payment->fresh()->status)->toBe('paid');
    $this->actingAs($this->finance)->delete("{$base}/tds")->assertSessionHas('error');
});

it('takes the Form 16A, and sends a certificate that does not match back with what is wrong', function () {
    $payment = ($this->bill)();
    $base = "/client/payments/{$payment->id}";
    $this->actingAs($this->finance)->post("{$base}/tds", ['section' => '194J-technical']);
    $deduction = TdsDeduction::query()->sole();
    expect($deduction->amount)->toBe(2_000_00);

    $this->actingAs($this->finance)->post("{$base}/tds/certificate", ['certificate' => UploadedFile::fake()->image('form.png')])->assertSessionHasErrors('certificate');
    $this->actingAs($this->finance)->post("{$base}/tds/certificate", ['certificate' => UploadedFile::fake()->create('form16a.pdf', 120, 'application/pdf')])->assertSessionHas('success');

    expect($deduction->fresh()->status)->toBe('received');
    Storage::disk('private')->assertExists($deduction->fresh()->certificate_path);
    Notification::assertSentTo($this->owner, TdsNotice::class, fn ($notification) => $notification->what === 'received');

    $this->actingAs($this->owner)->get('/admin/billing/tds')->assertOk()->assertInertia(fn ($page) => $page->has('table.rows', 1));
    $this->actingAs($this->owner)->post("/admin/billing/tds/{$deduction->id}/check", ['matches' => false])->assertSessionHas('error');
    $this->actingAs($this->owner)->post("/admin/billing/tds/{$deduction->id}/check", ['matches' => false, 'note' => 'Form 26AS shows ₹1,800, not ₹2,000.'])->assertSessionHas('success');

    expect($deduction->fresh()->status)->toBe('mismatch');
    Notification::assertSentTo([$this->acme, $this->finance], TdsNotice::class, fn ($notification) => $notification->what === 'mismatch');

    $kinds = collect($this->actingAs($this->finance)->get('/client')->viewData('page')['props']['waitingOnYou']);
    expect($kinds->firstWhere('kind', 'tds')['urgent'])->toBeTrue();

    $this->actingAs($this->finance)->post("{$base}/tds/certificate", ['certificate' => UploadedFile::fake()->create('form16a-corrected.pdf', 120, 'application/pdf')]);
    $this->actingAs($this->owner)->post("/admin/billing/tds/{$deduction->id}/check", ['matches' => true]);
    expect($deduction->fresh()->status)->toBe('verified');
    $this->actingAs($this->finance)->get("{$base}/tds/certificate")->assertOk();
});

it('knows which quarter a deduction falls in, and when its Form 16A is due', function (string $date, string $quarter, string $due) {
    $result = TdsDeduction::quarterOf(Carbon::parse($date));

    expect($result['quarter'])->toBe($quarter)->and($result['due']->toDateString())->toBe($due);
})->with([
    ['2026-05-10', '2026-27 Q1', '2026-08-15'],
    ['2026-09-30', '2026-27 Q2', '2026-11-15'],
    ['2026-11-17', '2026-27 Q3', '2027-02-15'],
    ['2027-02-01', '2026-27 Q4', '2027-06-15'],
]);
