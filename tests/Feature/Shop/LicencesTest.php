<?php

use App\Models\ClientMember;
use App\Models\Licence;
use App\Models\LicenceDownload;
use App\Models\Order;
use App\Models\ProductVersion;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payments\Checkout;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\ShopCatalogue;

/** A version of the code, stored the way an upload stores it. */
function storeVersion($solution, string $version, string $releasedOn, bool $available = true): ProductVersion
{
    $path = "products/{$solution->id}/".str()->uuid().'.zip';
    Storage::disk('private')->put($path, "zip bytes for {$version}");

    return $solution->versions()->create([
        'version' => $version, 'released_on' => $releasedOn, 'path' => $path, 'file_name' => "{$version}.zip",
        'size' => 20, 'sha256' => hash('sha256', "zip bytes for {$version}"), 'is_available' => $available,
    ]);
}

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed(PermissionSeeder::class);
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');

    $this->owner = User::factory()->owner()->create();
    $this->shop = ShopCatalogue::product();
    $this->solution = $this->shop['solution'];

    // An organisation: the account that bought, a technical member, a finance member.
    $this->buyer = User::factory()->client()->create();
    $member = function (string $role) {
        $user = User::factory()->client()->create();
        ClientMember::query()->create(['account_id' => $this->buyer->id, 'user_id' => $user->id, 'role' => $role, 'joined_at' => now()]);

        return $user;
    };
    $this->technical = $member('technical');
    $this->finance = $member('finance');
    $this->stranger = User::factory()->client()->create();

    $this->travelTo(now()->setDate(2026, 11, 18)->setTime(10, 0));
    $this->old = storeVersion($this->solution, '1.0.0', '2026-01-10');

    $this->actingAs($this->buyer)->post('/cart/lines', ['solution' => $this->solution->id, 'tier' => $this->shop['single']->id]);
    $total = $this->get('/cart?state=Maharashtra')->viewData('page')['props']['quote']['totalPaise'];
    $this->post('/cart/order', ['name' => 'Acme Clinics', 'state' => 'Maharashtra', 'accept_terms' => true, 'expected_total' => $total]);
    $this->order = Order::query()->sole();

    $checkout = app(Checkout::class);
    $this->transaction = $checkout->begin($this->order->paymentRequest, $this->buyer)['transaction'];
    $checkout->settle($this->transaction, ['outcome' => 'success']);

    $this->licence = Licence::query()->sole();
    $this->page = "/client/orders/{$this->order->id}";
});

it('shows the key once, keeps only its hash, and replaces it rather than recovering it', function () {
    $this->actingAs($this->finance)->get($this->page)->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/orders/Show')->where('mayHandleLicences', false)->where('licences.0.versions', []));
    $this->actingAs($this->finance)->post("/client/licences/{$this->licence->id}/key")->assertForbidden();
    $this->actingAs($this->stranger)->post("/client/licences/{$this->licence->id}/key")->assertNotFound();

    $response = $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/key");
    $key = $response->getSession()->get('issuedLicenceKey')['key'];

    expect($key)->toMatch('/^UBS(-[0-9A-HJKMNP-TV-Z]{5}){5}$/')
        ->and($this->licence->fresh()->key_hash)->toBe(hash('sha256', str_replace('-', '', $key)))
        ->and($this->licence->fresh()->key_prefix)->toBe(substr($key, 0, 9));

    // Typed back lower case with spaces, it is the same key.
    expect(Licence::findByKey(strtolower(str_replace('-', ' ', $key)))?->id)->toBe($this->licence->id);

    // Never shown again: asking again is refused, and the page has only the mask.
    $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/key")->assertSessionHas('error');
    $this->actingAs($this->technical)->get($this->page)->assertInertia(fn ($page) => $page->where('licences.0.maskedKey', substr($key, 0, 9).'-•••••-•••••-•••••-•••••'));

    $rotated = $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/key/rotate")->getSession()->get('issuedLicenceKey');
    expect($rotated['rotated'])->toBeTrue()
        ->and($rotated['key'])->not->toBe($key)
        ->and(Licence::findByKey($key))->toBeNull()
        ->and(Licence::findByKey($rotated['key'])?->id)->toBe($this->licence->id);
});

it('hands out downloads through signed links that expire, counted, within the update window', function () {
    $current = storeVersion($this->solution, '1.1.0', '2026-11-01');
    $withdrawn = storeVersion($this->solution, '1.0.1', '2026-03-01', available: false);
    // Released after this licence's updates end (18 Nov 2027).
    $future = storeVersion($this->solution, '2.0.0', '2027-12-01');

    $this->actingAs($this->technical)->get($this->page)
        ->assertInertia(fn ($page) => $page->where('licences.0.versions', fn ($versions) => collect($versions)->pluck('version')->all() === ['1.1.0', '1.0.0']));

    $link = $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/versions/{$current->id}/download")->assertRedirect()->headers->get('Location');
    expect($link)->toContain('/downloads/licences/')->toContain('signature=');

    auth()->logout();
    // From anywhere, for a few minutes: it is the signature that counts.
    $this->get($link)->assertOk()->assertDownload('clinic-desk-1.1.0.zip');
    expect(LicenceDownload::query()->sole()->user_id)->toBe($this->technical->id);

    // Tampered with, it is refused; expired, too.
    $this->get(str_replace("/versions/{$current->id}", "/versions/{$future->id}", $link))->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($link)->assertForbidden();

    // Outside the window, or withdrawn: not through the portal, and not with a hand made signature either.
    $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/versions/{$future->id}/download")->assertSessionHas('error');
    $forged = URL::temporarySignedRoute('downloads.licence', now()->addMinutes(5), ['licence' => $this->licence->id, 'version' => $withdrawn->id, 'by' => $this->technical->id]);
    auth()->logout();
    $this->get($forged)->assertForbidden();

    // A licence may fetch each version a limited number of times.
    config(['company.shop.downloads_per_version' => 2]);
    $again = URL::temporarySignedRoute('downloads.licence', now()->addMinutes(5), ['licence' => $this->licence->id, 'version' => $current->id, 'by' => $this->technical->id]);
    $this->get($again)->assertOk();
    $this->get($again)->assertForbidden();
});

it('withdraws the licence when the whole order is refunded, and not for a part refund', function () {
    $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/key");

    $this->actingAs($this->owner)->post("/admin/transactions/{$this->transaction->id}/refund", ['amount' => 1000, 'reason' => 'Installation not needed'])->assertSessionHas('success');
    $this->actingAs($this->owner)->post('/admin/refunds/'.Refund::query()->latest('id')->value('id').'/complete')->assertSessionHas('success');

    expect($this->order->fresh()->status)->toBe('partially_refunded')
        ->and($this->licence->fresh()->isActive())->toBeTrue();

    $left = ($this->transaction->amount - 1000_00) / 100;
    $this->actingAs($this->owner)->post("/admin/transactions/{$this->transaction->id}/refund", ['amount' => $left, 'reason' => 'Changed their mind within the window'])->assertSessionHas('success');
    $this->actingAs($this->owner)->post('/admin/refunds/'.Refund::query()->latest('id')->value('id').'/complete')->assertSessionHas('success');

    expect($this->order->fresh()->status)->toBe('refunded')
        ->and($this->licence->fresh()->status)->toBe('revoked')
        ->and($this->licence->fresh()->revoked_reason)->toBe("Order {$this->order->number} was refunded.");

    $this->actingAs($this->technical)->post("/client/licences/{$this->licence->id}/versions/{$this->old->id}/download")->assertSessionHas('error', 'This licence has been withdrawn.');
    $this->actingAs($this->technical)->get($this->page)->assertInertia(fn ($page) => $page->where('licences.0.active', false));
});

it('lists the organisation\'s orders for everybody in it, and nobody else', function () {
    $this->actingAs($this->finance)->get('/client/orders')->assertOk()
        ->assertInertia(fn ($page) => $page->component('client/orders/Index')
            ->has('table.rows', 1)
            ->where('table.rows.0.summary', 'Clinic desk')
            ->where('table.rows.0.status', 'Paid'));
    $this->actingAs($this->stranger)->get('/client/orders')->assertInertia(fn ($page) => $page->has('table.rows', 0));
    $this->actingAs($this->stranger)->get($this->page)->assertNotFound();
});
