<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\ClientMember;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Licence;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OneTimeCodeNotification;
use App\Notifications\OrderNotice;
use App\Services\Payments\Checkout;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopCatalogue;

/** The code the checkout sent to an address, read off the faked notification. */
function checkoutCodeFor(string $email): string
{
    $code = null;

    Notification::assertSentOnDemand(OneTimeCodeNotification::class, function ($notification, $channels, $notifiable) use ($email, &$code) {
        if (($notifiable->routes['mail'] ?? null) === $email) {
            $code = (new ReflectionProperty($notification, 'code'))->getValue($notification);

            return true;
        }

        return false;
    });

    return $code;
}

/** The cart's total as the page showed it, which placing the order must match. */
function shownTotal($test, string $state = 'Maharashtra'): int
{
    return $test->get('/cart?state='.urlencode($state))->viewData('page')['props']['quote']['totalPaise'];
}

beforeEach(function () {
    Notification::fake();
    $this->seed(PermissionSeeder::class);
    Setting::put('company.legal_name', 'Unboundbyte Solutions Private Limited', 'company');
    Setting::put('company.state', 'Madhya Pradesh', 'company');
    Setting::put('invoicing.default_tax_rate', 18, 'invoicing');
    config(['company.clients.two_factor_to_commit' => true]);

    $this->shop = ShopCatalogue::product();
    $this->solution = $this->shop['solution'];
    $this->owner = User::factory()->owner()->create();

    $this->addSingle = fn (array $addons = []) => $this->post('/cart/lines', [
        'solution' => $this->solution->id, 'tier' => $this->shop['single']->id, 'addons' => $addons,
    ]);
    $this->billing = ['name' => 'Acme Clinics Private Limited', 'gstin' => '', 'state' => 'Maharashtra', 'accept_terms' => true];
});

it('fills a guest\'s cart in the session and prices it on the server, GST split by state', function () {
    ($this->addSingle)([['id' => $this->shop['install']->id, 'quantity' => 1], ['id' => $this->shop['hours']->id, 'quantity' => 10]])
        ->assertRedirect('/cart');

    $this->get('/cart?state=Maharashtra')->assertOk()
        ->assertInertia(fn ($page) => $page->component('shop/Cart')
            ->where('seo.noindex', true)
            ->where('quote.lines.0.product', 'Clinic desk')
            ->where('quote.lines.0.amount', '₹49,999.00')
            ->where('quote.lines.0.addons.1.description', 'Customisation, 10 hours')
            ->where('quote.lines.0.addons.1.amount', '₹15,000.00')
            ->where('quote.subtotal', '₹69,999.00')
            ->where('quote.breakup.0.label', 'IGST @ 18%')
            ->where('quote.total', '₹82,598.82')
            ->where('cart', 1));

    $this->get('/cart?state=Madhya%20Pradesh')
        ->assertInertia(fn ($page) => $page->where('quote.breakup.0.label', 'CGST @ 9%')->where('quote.breakup.1.label', 'SGST @ 9%')->where('quote.total', '₹82,598.82'));

    // More hours than are sold at once are capped, and adding the product again replaces its line.
    ($this->addSingle)([['id' => $this->shop['hours']->id, 'quantity' => 999]]);
    $this->get('/cart')->assertInertia(fn ($page) => $page->has('quote.lines', 1)->where('quote.lines.0.addons.0.description', 'Customisation, 40 hours'));
});

it('sells only what is on sale, whatever the page sends', function () {
    $other = ShopCatalogue::product('Pharmacy desk');

    // Somebody else's tier, a switched off tier, an unpublished product.
    $this->post('/cart/lines', ['solution' => $this->solution->id, 'tier' => $other['single']->id])->assertNotFound();
    $this->shop['multi']->update(['is_active' => false]);
    $this->post('/cart/lines', ['solution' => $this->solution->id, 'tier' => $this->shop['multi']->id])->assertNotFound();
    $other['solution']->update(['is_published' => false]);
    $this->post('/cart/lines', ['solution' => $other['solution']->id, 'tier' => $other['single']->id])->assertNotFound();

    // Something that goes off sale once it is in the cart is taken out, and the cart says so.
    ($this->addSingle)();
    $this->shop['single']->update(['is_active' => false]);
    $this->get('/cart')->assertInertia(fn ($page) => $page->has('quote.lines', 0)->where('quote.notices.0', fn ($text) => str_contains($text, 'no longer on sale')));
});

it('holds codes to their rules, on the server', function () {
    $code = fn (string $code, array $rules) => Coupon::query()->create(['code' => $code, 'is_active' => true, ...$rules]);
    $code('TENOFF', ['percent' => 10, 'max_discount' => 4_000_00]);
    $code('FLAT2K', ['amount' => 2_000_00, 'stackable' => true]);
    $code('BIGONLY', ['percent' => 5, 'min_order' => 1_00_000_00]);
    $code('LATER', ['percent' => 5, 'starts_at' => now()->addDay()]);
    $code('GONE', ['percent' => 5, 'ends_at' => now()->subMinute()]);
    $code('PHARMACY', ['percent' => 5, 'solution_ids' => [999]]);
    $code('OFF', ['percent' => 5, 'is_active' => false]);

    ($this->addSingle)([['id' => $this->shop['install']->id, 'quantity' => 1]]);

    foreach ([
        'NOPE' => 'This code is not valid.',
        'OFF' => 'This code is not valid.',
        'BIGONLY' => 'This code needs an order of at least ₹1,00,000 before tax.',
        'LATER' => 'This code can be used from',
        'GONE' => 'This code has expired.',
        'PHARMACY' => 'This code does not apply to anything in your cart.',
    ] as $attempt => $reason) {
        $this->post('/cart/coupons', ['code' => strtolower($attempt)])->assertSessionHasErrors('code');
        expect(session('errors')->first('code'))->toStartWith($reason);
    }

    // 10% of ₹54,999 is ₹5,499.90, capped at ₹4,000.
    $this->post('/cart/coupons', ['code' => ' tenoff '])->assertSessionHas('success', 'TENOFF applied: ₹4,000.00 off.');
    // Not stackable with it, whichever way round.
    $this->post('/cart/coupons', ['code' => 'FLAT2K'])->assertSessionHasErrors(['code' => 'This code cannot be combined with TENOFF.']);

    $this->get('/cart?state=Maharashtra')->assertInertia(fn ($page) => $page
        ->where('quote.discount', '₹4,000.00')
        ->where('quote.taxable', '₹50,999.00')
        // The discount is shared across the lines it applies to, to the paisa.
        ->where('quote.lines.0.discount', '₹3,636.36')
        ->where('quote.lines.0.addons.0.discount', '₹363.64')
        ->where('quote.total', '₹60,178.82'));
});

it('shares a stack of codes without ever taking more than a line is worth', function () {
    Coupon::query()->create(['code' => 'HALF', 'percent' => 50, 'stackable' => true, 'is_active' => true]);
    Coupon::query()->create(['code' => 'FLAT40K', 'amount' => 40_000_00, 'stackable' => true, 'is_active' => true]);

    ($this->addSingle)();
    $this->post('/cart/coupons', ['code' => 'HALF'])->assertSessionHas('success');
    $this->post('/cart/coupons', ['code' => 'FLAT40K'])->assertSessionHas('success', 'FLAT40K applied: ₹24,999.50 off.');

    $this->get('/cart')->assertInertia(fn ($page) => $page->where('quote.taxable', '₹0.00')->where('quote.total', '₹0.00'));
});

it('answers a new address and a known one alike, and only the code decides', function () {
    $known = User::factory()->client()->create(['email' => 'known@example.com']);

    $new = $this->post('/checkout/code', ['name' => 'Meera Iyer', 'email' => 'Meera@Example.com', 'company' => 'Acme Clinics']);
    $this->flushSession();
    $existing = $this->post('/checkout/code', ['name' => 'Meera Iyer', 'email' => 'known@example.com', 'company' => 'Acme Clinics']);

    expect($new->getSession()->get('checkoutCodeSentTo'))->not->toBeNull()
        ->and($existing->getSession()->get('checkoutCodeSentTo'))->not->toBeNull()
        ->and($new->headers->get('Location'))->toBe($existing->headers->get('Location'));
    // Both by mail to the address, never through an account.
    Notification::assertNotSentTo($known, OneTimeCodeNotification::class);
    checkoutCodeFor('meera@example.com');
    checkoutCodeFor('known@example.com');
    expect(User::query()->where('email', 'meera@example.com')->exists())->toBeFalse();
});

it('makes a client account for a new buyer, signs them in and keeps their cart', function () {
    ($this->addSingle)();
    $this->post('/checkout/code', ['name' => 'Meera Iyer', 'email' => 'meera@example.com', 'mobile' => '+919876543210', 'company' => 'Acme Clinics']);

    $this->post('/checkout/code/verify', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->post('/checkout/code/verify', ['code' => checkoutCodeFor('meera@example.com')])->assertRedirect('/cart');

    $buyer = User::query()->where('email', 'meera@example.com')->sole();
    $this->assertAuthenticatedAs($buyer);
    expect($buyer->isClient())->toBeTrue()
        ->and($buyer->password)->toBeNull()
        ->and($buyer->must_change_password)->toBeFalse()
        ->and($buyer->hasVerifiedEmail())->toBeTrue()
        ->and($buyer->profile->company)->toBe('Acme Clinics')
        ->and(AuditLog::query()->where('action', 'account.created')->where('subject_id', $buyer->id)->sole()->changes)->toBe(['role' => 'client', 'via' => 'checkout'])
        ->and(Cart::query()->where('user_id', $buyer->id)->sole()->lines[0]['tier'])->toBe($this->shop['single']->id);

    $this->get('/cart')->assertInertia(fn ($page) => $page->where('buyer.canBuy', true)->where('billing.name', 'Acme Clinics')->has('quote.lines', 1));
});

it('signs a known client in through the pipeline, and turns away a staff address', function () {
    $known = User::factory()->client()->create(['email' => 'known@example.com']);
    $staff = User::factory()->admin()->create(['email' => 'staff@example.com']);

    $this->post('/checkout/code', ['name' => 'Known', 'email' => 'known@example.com']);
    $this->post('/checkout/code/verify', ['code' => checkoutCodeFor('known@example.com')])->assertRedirect('/cart');
    $this->assertAuthenticatedAs($known);
    expect(User::query()->count())->toBe(3);

    auth()->logout();
    $this->flushSession();

    $this->post('/checkout/code', ['name' => 'Staff', 'email' => 'staff@example.com']);
    $this->post('/checkout/code/verify', ['code' => checkoutCodeFor('staff@example.com')])
        ->assertSessionHasErrors(['code' => 'This address belongs to a staff or learning account here, which cannot buy. Use the address you use for work.']);
    $this->assertGuest();
});

it('places an order from the cart, holding its code, and only at the total the buyer saw', function () {
    $buyer = User::factory()->client()->create();
    Coupon::query()->create(['code' => 'TENOFF', 'percent' => 10, 'is_active' => true, 'max_uses' => 1]);
    $this->actingAs($buyer);

    ($this->addSingle)([['id' => $this->shop['support']->id, 'quantity' => 6]]);
    $this->post('/cart/coupons', ['code' => 'TENOFF']);

    $this->post('/cart/order', [...$this->billing, 'expected_total' => 1])->assertRedirect()->assertSessionHas('error', fn ($text) => str_starts_with($text, 'The total is now'));
    $this->post('/cart/order', [...$this->billing, 'gstin' => '23ABCDE1234F1Z5', 'expected_total' => shownTotal($this)])
        ->assertSessionHasErrors(['gstin' => 'This GSTIN is registered in Madhya Pradesh. Choose that state, or check the number.']);

    $this->post('/cart/order', [...$this->billing, 'gstin' => '27abcde1234f1z5', 'expected_total' => shownTotal($this)])->assertRedirect();

    $order = Order::query()->sole();
    $payment = PaymentRequest::query()->sole();
    expect($order->number)->toBe('ORD-00001')
        ->and($order->status)->toBe('pending')
        ->and($order->subtotal)->toBe(61_999_00)
        ->and($order->discount)->toBe(6_199_90)
        ->and($order->taxable)->toBe(55_799_10)
        ->and($order->tax)->toBe(10_043_84)
        ->and($order->total)->toBe(65_842_94)
        ->and($order->billing['gstin'])->toBe('27ABCDE1234F1Z5')
        ->and($order->items)->toHaveCount(2)
        ->and($order->items[1]->parent_id)->toBe($order->items[0]->id)
        ->and($order->items[0]->discount + $order->items[1]->discount)->toBe(6_199_90)
        ->and($payment->payable->is($order))->toBeTrue()
        ->and($payment->total)->toBe($order->total)
        ->and(CouponRedemption::query()->sole()->status)->toBe('held')
        ->and(Cart::query()->where('user_id', $buyer->id)->sole()->lines)->toBe([]);

    // The single use is held for this order: nobody else gets it meanwhile.
    $rival = User::factory()->client()->create();
    $this->actingAs($rival);
    ($this->addSingle)();
    $this->post('/cart/coupons', ['code' => 'TENOFF'])->assertSessionHasErrors(['code' => 'This code has been used up.']);

    // The buyer's own billing details were empty, so they fill the profile.
    expect($buyer->profile()->first()->state)->toBe('Maharashtra');
});

it('invoices every line with the discount, and delivers the order once it is paid, with no two-step needed by the buyer', function () {
    $buyer = User::factory()->client()->create(['name' => 'Meera Iyer']);
    $finance = User::factory()->client()->create();
    ClientMember::query()->create(['account_id' => $buyer->id, 'user_id' => $finance->id, 'role' => 'finance', 'joined_at' => now()]);
    Coupon::query()->create(['code' => 'TENOFF', 'percent' => 10, 'is_active' => true]);

    $this->actingAs($buyer);
    ($this->addSingle)([['id' => $this->shop['install']->id, 'quantity' => 1], ['id' => $this->shop['support']->id, 'quantity' => 3]]);
    $this->post('/cart/coupons', ['code' => 'TENOFF']);
    $this->post('/cart/order', [...$this->billing, 'expected_total' => shownTotal($this)]);
    $order = Order::query()->sole();

    // Finance pays the organisation's bills only with two-step sign in (CL20); the buyer pays their own order.
    $this->actingAs($finance)->postJson("/client/payments/{$order->payment_request_id}/checkout")->assertForbidden();
    $this->actingAs($buyer)->get("/client/payments/{$order->payment_request_id}")->assertInertia(fn ($page) => $page->where('payment.canPay', true)->where('payment.orderId', $order->id));
    $begun = $this->actingAs($buyer)->postJson("/client/payments/{$order->payment_request_id}/checkout")->assertOk();

    $invoice = Invoice::query()->sole();
    expect($invoice->items)->toHaveCount(3)
        ->and($invoice->items[0]->description)->toBe('Clinic desk, Single site licence')
        ->and($invoice->items[0]->hsn_sac)->toBe('997331')
        ->and($invoice->items[1]->hsn_sac)->toBe('998314')
        ->and($invoice->subtotal)->toBe($order->subtotal)
        ->and($invoice->discount)->toBe($order->discount)
        ->and($invoice->taxable())->toBe($order->taxable)
        ->and($invoice->tax_breakup[0]['label'])->toBe('IGST')
        ->and($invoice->total)->toBe($order->total)
        ->and($invoice->billed_to['name'])->toBe('Acme Clinics Private Limited');

    $this->actingAs($buyer)->post("/client/payments/{$begun->json('transaction')}/confirm", ['outcome' => 'success'])
        ->assertRedirect("/client/orders/{$order->id}");

    $order->refresh();
    $licence = Licence::query()->sole();
    expect($order->status)->toBe('paid')
        ->and($invoice->fresh()->status)->toBe('paid')
        ->and(CouponRedemption::query()->sole()->status)->toBe('redeemed')
        ->and($licence->client_id)->toBe($buyer->id)
        ->and($licence->sites)->toBe(1)
        ->and($licence->updates_until->toDateString())->toBe(today()->addYear()->toDateString())
        // Three months with the tier and three bought as an add-on.
        ->and($licence->support_until->toDateString())->toBe(today()->addMonths(6)->toDateString())
        ->and($licence->hasKey())->toBeFalse();

    Notification::assertSentTo([$buyer, $finance], OrderNotice::class, fn ($notice) => $notice->what === 'paid');
    Notification::assertSentTo($this->owner, OrderNotice::class, fn ($notice) => $notice->what === 'received');

    // Paying again changes nothing.
    app(Checkout::class)->settle($order->paymentRequest->transactions()->sole(), ['outcome' => 'success']);
    expect(Licence::query()->count())->toBe(1);
});

it('starts a subscription for a hosted plan bought in the cart', function () {
    $plan = $this->solution->tiers()->create(['kind' => 'plan', 'name' => 'Hosted', 'price' => 2_499_00, 'interval' => 'monthly']);
    $this->solution->update(['offerings' => ['source_code', 'subscription']]);
    $buyer = User::factory()->client()->create();

    $this->actingAs($buyer)->post('/cart/lines', ['solution' => $this->solution->id, 'tier' => $plan->id, 'addons' => [['id' => $this->shop['support']->id, 'quantity' => 1]]]);
    // Months of licence support mean nothing on a plan, so that add-on is left out, and said so once.
    $this->get('/cart')->assertInertia(fn ($page) => $page->where('quote.lines.0.addons', [])->where('quote.notices.0', 'Clinic desk: Extra support goes with a licence, not a plan, so it was left out.'));
    $this->get('/cart')->assertInertia(fn ($page) => $page->where('quote.notices', []));

    $this->post('/cart/order', [...$this->billing, 'expected_total' => shownTotal($this)]);
    $order = Order::query()->sole();
    app(Checkout::class)->settle(app(Checkout::class)->begin($order->paymentRequest, $buyer)['transaction'], ['outcome' => 'success']);

    $subscription = $buyer->subscriptions()->sole();
    expect($subscription->amount)->toBe(2_499_00)
        ->and($subscription->interval)->toBe('monthly')
        ->and($subscription->auto_renew)->toBeTrue()
        ->and($subscription->renews_on->toDateString())->toBe(today()->addMonth()->toDateString())
        ->and(Licence::query()->count())->toBe(0);
});

it('lets staff and students look but not buy', function () {
    $student = User::factory()->create(['role' => Role::Student]);
    $this->actingAs($student);
    ($this->addSingle)();

    $this->get('/cart')->assertInertia(fn ($page) => $page->where('buyer.canBuy', false));
    $this->post('/cart/order', [...$this->billing, 'expected_total' => 0])->assertSessionHas('error', fn ($text) => str_starts_with($text, 'Products are bought on a client account.'));
    expect(Order::query()->count())->toBe(0);
});

it('cancels an unpaid order and frees its code, and lapses orders nobody paid', function () {
    $buyer = User::factory()->client()->create();
    Coupon::query()->create(['code' => 'ONCE', 'percent' => 10, 'is_active' => true, 'max_uses' => 1]);

    $this->actingAs($buyer);
    ($this->addSingle)();
    $this->post('/cart/coupons', ['code' => 'ONCE']);
    $this->post('/cart/order', [...$this->billing, 'expected_total' => shownTotal($this)]);
    $order = Order::query()->sole();

    $this->actingAs(User::factory()->client()->create())->post("/client/orders/{$order->id}/cancel")->assertNotFound();
    $this->actingAs($buyer)->post("/client/orders/{$order->id}/cancel")->assertSessionHas('success');

    expect($order->fresh()->status)->toBe('cancelled')
        ->and($order->paymentRequest->fresh()->status)->toBe('cancelled')
        ->and(CouponRedemption::query()->sole()->status)->toBe('released');

    // Placed again, then left: it lapses after a week, and the code is free again.
    ($this->addSingle)();
    $this->post('/cart/coupons', ['code' => 'ONCE'])->assertSessionHas('success');
    $this->post('/cart/order', [...$this->billing, 'expected_total' => shownTotal($this)]);
    $this->travel(8)->days();
    $this->artisan('shop:expire-orders')->assertSuccessful();

    expect(Order::query()->where('status', 'cancelled')->count())->toBe(2)
        ->and(CouponRedemption::query()->where('status', 'released')->count())->toBe(2);
});
