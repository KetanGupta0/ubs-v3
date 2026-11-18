<?php

use App\Models\Coupon;
use App\Models\Lead;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeadReceived;
use App\Services\Payments\Checkout;
use App\Services\Shop\Orders;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopCatalogue;

/** Staff holding exactly these permissions. */
function shopStaff(array $keys): User
{
    $user = User::factory()->admin()->create();
    $user->permissions()->attach(Permission::query()->whereIn('key', $keys)->get());

    return $user;
}

/** A paid order for a single site licence, placed through the cart like a buyer would. */
function paidShopOrder($test, array $shop, ?string $code = null): Order
{
    $buyer = User::factory()->client()->create();
    $test->actingAs($buyer)->post('/cart/lines', ['solution' => $shop['solution']->id, 'tier' => $shop['single']->id]);

    if ($code) {
        $test->post('/cart/coupons', ['code' => $code]);
    }

    $total = $test->get('/cart?state=Maharashtra')->viewData('page')['props']['quote']['totalPaise'];
    $test->post('/cart/order', ['name' => 'Acme Clinics', 'state' => 'Maharashtra', 'accept_terms' => true, 'expected_total' => $total]);

    $order = Order::query()->latest('id')->first();
    $checkout = app(Checkout::class);
    $checkout->settle($checkout->begin($order->paymentRequest, $buyer)['transaction'], ['outcome' => 'success']);

    return $order->fresh();
}

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');
    $this->owner = User::factory()->owner()->create();
    $this->shop = ShopCatalogue::product();
});

it('keeps discount codes for staff who manage them, and keeps a used one on record', function () {
    $this->actingAs(shopStaff(['orders.view']))->get('/admin/coupons')->assertForbidden();
    $marketing = shopStaff(['coupons.manage']);

    $this->actingAs($marketing)->post('/admin/coupons', ['code' => 'x', 'kind' => 'percent'])->assertSessionHasErrors(['code', 'percent']);
    $this->actingAs($marketing)->post('/admin/coupons', ['code' => 'diwali-10', 'kind' => 'percent', 'percent' => 10, 'max_discount' => 5000, 'starts_at' => '2026-11-20T00:00', 'ends_at' => '2026-11-19T00:00'])
        ->assertSessionHasErrors('ends_at');
    $this->actingAs($marketing)->post('/admin/coupons', [
        'code' => ' diwali-10 ', 'description' => 'Festival offer', 'kind' => 'percent', 'percent' => 10, 'max_discount' => 5000,
        'max_uses' => 100, 'max_uses_per_account' => 1, 'solution_ids' => [$this->shop['solution']->id], 'is_active' => true,
    ])->assertSessionHas('success');

    $coupon = Coupon::query()->sole();
    expect($coupon->code)->toBe('DIWALI-10')
        ->and($coupon->max_discount)->toBe(5_000_00)
        ->and($coupon->solution_ids)->toBe([$this->shop['solution']->id])
        ->and($coupon->created_by)->toBe($marketing->id);
    $this->actingAs($marketing)->post('/admin/coupons', ['code' => 'Diwali-10', 'kind' => 'amount', 'amount' => 100])->assertSessionHasErrors('code');

    $this->actingAs($marketing)->get('/admin/coupons')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/shop/Coupons')
            ->where('table.rows.0.value', '10% off, up to ₹5,000')
            ->where('table.rows.0.applies', '1 product')
            ->where('table.rows.0.state', 'On'));

    paidShopOrder($this, $this->shop, 'DIWALI-10');

    // Used: its name stays, and it is switched off rather than deleted.
    $this->actingAs($marketing)->put("/admin/coupons/{$coupon->id}", ['code' => 'NEWNAME', 'kind' => 'percent', 'percent' => 15])->assertSessionHasErrors('code');
    // The dialog sends every field, as here.
    $this->actingAs($marketing)->put("/admin/coupons/{$coupon->id}", [
        'code' => 'DIWALI-10', 'description' => 'Festival offer', 'kind' => 'percent', 'percent' => 15, 'max_discount' => 5000,
        'max_uses' => 100, 'max_uses_per_account' => 1, 'solution_ids' => [$this->shop['solution']->id], 'is_active' => true,
    ])->assertSessionHas('success');
    $this->actingAs($marketing)->delete("/admin/coupons/{$coupon->id}")->assertSessionHas('error');
    $this->actingAs($marketing)->post("/admin/coupons/{$coupon->id}/toggle")->assertSessionHas('success');

    expect($coupon->fresh()->percent)->toBe(15)
        ->and($coupon->fresh()->is_active)->toBeFalse();
    $this->actingAs($marketing)->get('/admin/coupons')->assertInertia(fn ($page) => $page->where('table.rows.0.used', '1 of 100')->where('table.rows.0.state', 'Off'));
});

it('shows orders and licences to staff who may see them, and lets those who manage them act', function () {
    $order = paidShopOrder($this, $this->shop);
    $licence = Licence::query()->sole();
    LicenceActivation::query()->create(['licence_id' => $licence->id, 'domain' => 'clinic.example.com', 'first_seen_at' => now(), 'last_seen_at' => now()]);

    $this->actingAs(shopStaff(['billing.view']))->get('/admin/orders')->assertForbidden();

    $viewer = shopStaff(['orders.view']);
    $this->actingAs($viewer)->get('/admin/orders')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/shop/Orders')->where('table.rows.0.number', $order->number)->where('table.rows.0.status', 'Paid'));
    $this->actingAs($viewer)->get("/admin/orders/{$order->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/shop/Order')
            ->where('mayManage', false)
            ->where('licences.0.activations.0.domain', 'clinic.example.com')
            ->where('order.lines.0.sac', '997331'));
    $this->actingAs($viewer)->get('/admin/licences?search=UBS')->assertOk();
    $this->actingAs($viewer)->post("/admin/licences/{$licence->id}/revoke", ['reason' => 'Test'])->assertForbidden();

    $manager = shopStaff(['orders.view', 'orders.manage']);
    $this->actingAs($manager)->post("/admin/licences/{$licence->id}/revoke", [])->assertSessionHasErrors('reason');
    $this->actingAs($manager)->post("/admin/licences/{$licence->id}/revoke", ['reason' => 'Chargeback from the bank'])->assertSessionHas('success');
    expect($licence->fresh()->status)->toBe('revoked');
    $this->actingAs($manager)->post("/admin/licences/{$licence->id}/restore")->assertSessionHas('success');
    expect($licence->fresh()->isActive())->toBeTrue();

    // Updates can be extended, never cut short; the reason goes in the audit log.
    $this->actingAs($manager)->put("/admin/licences/{$licence->id}/extend", ['updates_until' => today()->toDateString(), 'reason' => 'x'])->assertSessionHasErrors('updates_until');
    $this->actingAs($manager)->put("/admin/licences/{$licence->id}/extend", ['updates_until' => today()->addYears(2)->toDateString(), 'reason' => 'Agreed at handover'])->assertSessionHas('success');
    expect($licence->fresh()->updates_until->toDateString())->toBe(today()->addYears(2)->toDateString());

    $this->actingAs($manager)->delete("/admin/licences/{$licence->id}/sites/".LicenceActivation::query()->sole()->id)->assertSessionHas('success');
    expect(LicenceActivation::query()->sole()->released_by)->toBe($manager->id);

    // A paid order is not cancelled; an unpaid one is, with a reason.
    $this->actingAs($manager)->post("/admin/orders/{$order->id}/cancel", ['reason' => 'Duplicate'])->assertSessionHas('error');
    $buyer = User::factory()->client()->create();
    $this->actingAs($buyer)->post('/cart/lines', ['solution' => $this->shop['solution']->id, 'tier' => $this->shop['single']->id]);
    $total = $this->get('/cart?state=Maharashtra')->viewData('page')['props']['quote']['totalPaise'];
    $this->post('/cart/order', ['name' => 'Beta Labs', 'state' => 'Maharashtra', 'accept_terms' => true, 'expected_total' => $total]);
    $pending = Order::query()->latest('id')->first();
    $this->actingAs($manager)->post("/admin/orders/{$pending->id}/cancel", ['reason' => 'Asked us to, on the phone'])->assertSessionHas('success');
    expect($pending->fresh()->status)->toBe('cancelled');
});

it('reports what the shop sold, before tax, and what was left unpaid', function () {
    Coupon::query()->create(['code' => 'TENOFF', 'percent' => 10, 'is_active' => true]);
    paidShopOrder($this, $this->shop, 'TENOFF');
    paidShopOrder($this, $this->shop);

    // One more placed and never paid.
    $buyer = User::factory()->client()->create();
    $this->actingAs($buyer)->post('/cart/lines', ['solution' => $this->shop['solution']->id, 'tier' => $this->shop['multi']->id]);
    $total = $this->get('/cart?state=Maharashtra')->viewData('page')['props']['quote']['totalPaise'];
    $this->post('/cart/order', ['name' => 'Gamma', 'state' => 'Maharashtra', 'accept_terms' => true, 'expected_total' => $total]);
    app(Orders::class)->cancel(Order::query()->latest('id')->first(), 'Not paid in time.');

    $this->actingAs(shopStaff(['billing.view']))->get('/admin/reports/sales')->assertNotFound();

    $this->actingAs(shopStaff(['orders.view']))->get('/admin/reports/sales')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('report.summary.0.value', '₹94,998.10')
            ->where('report.summary.0.hint', '2 orders paid')
            ->where('report.summary.2.value', '₹4,999.90')
            ->where('report.summary.3.value', '1')
            ->where('report.summary.3.hint', '33% of 3 placed')
            ->where('report.charts.1.categories', ['Clinic desk'])
            ->has('report.rows', 2));
});

it('makes a lead from the live demo only when the visitor asks and agrees', function () {
    $solution = $this->shop['solution'];
    $this->post("/solutions/{$solution->slug}/demo", ['name' => 'Meera', 'email' => 'meera@example.com', 'consent' => true])->assertNotFound();

    $solution->update(['offerings' => ['source_code', 'demo'], 'demo_url' => 'https://clinic-demo.example.com']);

    $this->post("/solutions/{$solution->slug}/demo", ['name' => 'Meera', 'email' => 'meera@example.com'])->assertSessionHasErrors('consent');
    $this->post("/solutions/{$solution->slug}/demo", ['name' => 'Meera', 'email' => 'Meera@Example.com', 'note' => 'Billing module please', 'consent' => true])->assertSessionHas('success');

    $lead = Lead::query()->sole();
    expect($lead->interest)->toBe('demo')
        ->and($lead->email)->toBe('meera@example.com')
        ->and($lead->solution_id)->toBe($solution->id)
        ->and($lead->subject())->toBe('Live demo: Clinic desk')
        ->and($lead->message)->toContain('Billing module please');
    Notification::assertSentTo($this->owner, LeadReceived::class);
});
