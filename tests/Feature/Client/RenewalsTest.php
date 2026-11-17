<?php

use App\Models\ClientMember;
use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\RenewalItem;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ExpiryReminder;
use App\Notifications\PaymentRequested;
use App\Notifications\RenewalDue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');
    $this->travelTo(Carbon::parse('2026-11-17 09:00'));

    $this->owner = User::factory()->owner()->create();
    $this->acme = User::factory()->client()->create(['name' => 'Priya Acme']);
    $this->acme->profile()->updateOrCreate([], ['company' => 'Acme Logistics']);
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->acme->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->finance = $member('finance');
    $this->technical = $member('technical');

    $this->watch = fn (array $overrides = []) => $this->actingAs($this->owner)->post('/admin/renewals', [
        'client_id' => $this->acme->id, 'kind' => 'domain', 'name' => 'acmelogistics.in', 'provider' => 'A registrar',
        'expires_on' => '2026-12-17', 'auto_renew' => false, ...$overrides,
    ]);
});

it('watches a client\'s domain and reminds both sides once per window', function () {
    ($this->watch)()->assertSessionHas('success');
    ($this->watch)(['kind' => 'ssl', 'name' => 'Certificate for acmelogistics.in', 'expires_on' => '2026-11-30']);

    $this->actingAs($this->finance)->get('/client/renewals')
        ->assertInertia(fn ($page) => $page->component('client/Renewals')->has('renewals', 2)->where('renewals.0.name', 'Certificate for acmelogistics.in'));
    expect(collect($this->actingAs($this->finance)->get('/client')->viewData('page')['props']['waitingOnYou'])->where('kind', 'renewal')->count())->toBe(2);

    $this->artisan('renewals:remind')->assertSuccessful();

    // The domain, 30 days out: finance and the owner of the organisation, and our side, because a lapsed domain takes a site down.
    Notification::assertSentTo([$this->acme, $this->finance, $this->owner], ExpiryReminder::class, fn ($notice) => $notice->name === 'acmelogistics.in' && $notice->daysAhead === 30);
    Notification::assertNotSentTo($this->technical, ExpiryReminder::class, fn ($notice) => $notice->name === 'acmelogistics.in');
    // The certificate, 13 days out, goes to the technical people.
    Notification::assertSentTo($this->technical, ExpiryReminder::class, fn ($notice) => $notice->name === 'Certificate for acmelogistics.in');

    Notification::fake();
    $this->artisan('renewals:remind');
    Notification::assertNothingSent();

    $this->travel(16)->days();
    $this->artisan('renewals:remind');
    Notification::assertSentTo($this->finance, ExpiryReminder::class, fn ($notice) => $notice->name === 'acmelogistics.in' && $notice->daysAhead === 14);

    // Renewed: a new date, and the reminders start again for it.
    $item = RenewalItem::query()->where('kind', 'domain')->sole();
    $this->actingAs($this->owner)->put("/admin/renewals/{$item->id}", ['client_id' => $this->acme->id, 'kind' => 'domain', 'name' => 'acmelogistics.in', 'expires_on' => '2027-12-17'])->assertSessionHas('success');
    expect($item->fresh()->reminders_sent)->toBe([]);
});

it('reminds about contracts ending, and sends subscription reminders to finance', function () {
    $contract = MaintenanceContract::query()->create([
        'client_id' => $this->acme->id, 'reference' => 'AMC-0007', 'plan' => 'Standard', 'starts_on' => '2025-11-27', 'ends_on' => '2026-11-27', 'status' => 'active',
    ]);
    Subscription::query()->create([
        'client_id' => $this->acme->id, 'name' => 'Managed hosting', 'amount' => 18_000_00, 'interval' => 'yearly',
        'starts_on' => '2025-12-01', 'renews_on' => '2026-12-01', 'status' => 'active',
    ]);

    $this->artisan('renewals:remind');

    Notification::assertSentTo([$this->finance, $this->owner], ExpiryReminder::class, fn ($notice) => str_contains($notice->name, 'AMC-0007') && $notice->daysAhead === 10);
    Notification::assertSentTo([$this->acme, $this->finance], RenewalDue::class);
    expect($contract->fresh()->reminders_sent)->toBe([14]);
});

it('bills a subscription that renews itself on its date, once, and moves it on a period', function () {
    $subscription = Subscription::query()->create([
        'client_id' => $this->acme->id, 'name' => 'Managed hosting', 'amount' => 18_000_00, 'interval' => 'yearly',
        'starts_on' => '2025-11-17', 'renews_on' => '2026-11-17', 'auto_renew' => true, 'status' => 'active', 'reminders_sent' => [30, 14, 3],
    ]);

    $this->artisan('renewals:remind')->assertSuccessful();
    $this->artisan('renewals:remind');

    $payment = PaymentRequest::query()->sole();
    expect($payment->payable_type)->toBe(Subscription::class)
        ->and($payment->user_id)->toBe($this->acme->id)
        ->and($payment->subtotal)->toBe(18_000_00)
        ->and($payment->total)->toBe(21_240_00)
        ->and($payment->title)->toBe('Managed hosting: 17 Nov 2026 to 16 Nov 2027')
        ->and($payment->invoice)->not->toBeNull()
        ->and($subscription->fresh()->renews_on->toDateString())->toBe('2027-11-17')
        ->and($subscription->fresh()->reminders_sent)->toBe([]);
    Notification::assertSentTo([$this->acme, $this->finance], PaymentRequested::class);
});
