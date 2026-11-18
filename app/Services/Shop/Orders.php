<?php

namespace App\Services\Shop;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Licence;
use App\Models\Order;
use App\Models\PaymentRequest;
use App\Models\Solution;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\OrderNotice;
use App\Services\Admin\Auditor;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * An order, from the cart to what was bought (§4).
 *
 * Placing one prices the cart on the server, holds its codes and raises a
 * payment request for the total, which is paid like any other (`Checkout`),
 * and invoiced like any other, with a line for everything on it. When the
 * request is paid, wherever the money came in, `Invoicer` calls `fulfil()`:
 * licences are issued, plans start their subscriptions, and both sides are
 * told. A refund of the whole order withdraws what it gave.
 */
class Orders
{
    public function __construct(
        protected Pricing $pricing,
        protected Coupons $coupons,
        protected Licences $licences,
        protected CartStore $carts,
        protected Auditor $auditor,
    ) {}

    /**
     * Turn the buyer's cart into an order waiting for payment.
     *
     * Refused, with the reason, when the cart changed under them: something
     * went off sale, a code no longer applies, or the total is not the one
     * they were shown. They see the cart again and decide.
     *
     * @param  array{name: string, gstin: ?string, state: string, address: ?string, city: ?string, postal_code: ?string}  $billing
     * @param  int|null  $expectedTotal  The total the buyer was shown, in paise.
     */
    public function place(User $buyer, array $billing, ?int $expectedTotal): Order
    {
        if (! $buyer->isClient()) {
            throw new InvalidArgumentException('Products are bought on a client account.');
        }

        $client = $buyer->clientAccount();
        $contents = $this->carts->contents($buyer);
        $quote = $this->pricing->quote($contents, $client, $billing['state']);

        if ($quote->notices !== []) {
            // Take out what cannot be sold, so the cart they see next is the true one.
            $this->carts->keepOnly($buyer, $quote);

            throw new InvalidArgumentException(implode(' ', $quote->notices));
        }

        if ($quote->isEmpty()) {
            throw new InvalidArgumentException('Your cart is empty.');
        }

        if ($refused = $quote->refusedCoupons()) {
            foreach ($refused as $coupon) {
                $this->carts->removeCoupon($buyer, $coupon['code']);
            }

            throw new InvalidArgumentException("{$refused[0]['code']}: {$refused[0]['refusal']} It has been taken off, so check the new total.");
        }

        if ($expectedTotal !== null && $expectedTotal !== $quote->total()) {
            throw new InvalidArgumentException('The total is now '.Money::display($quote->total()).'. Check it and place the order again.');
        }

        $order = DB::transaction(function () use ($buyer, $client, $billing, $quote) {
            $this->holdCoupons($quote, $client);

            $order = Order::query()->create([
                'client_id' => $client->id,
                'user_id' => $buyer->id,
                'status' => 'pending',
                'subtotal' => $quote->subtotal,
                'discount' => $quote->discount,
                'taxable' => $quote->taxable(),
                'tax_rate' => $quote->taxRate,
                'tax_breakup' => $quote->breakup,
                'tax' => $quote->tax,
                'total' => $quote->total(),
                'billing' => [...$billing, 'contact' => $buyer->name, 'email' => $buyer->email, 'mobile' => $buyer->mobile],
                'place_of_supply' => $billing['state'],
            ]);

            $created = [];

            foreach ($quote->items as $index => $item) {
                $created[$index] = $order->items()->create([
                    'parent_id' => $item['parent'] === null ? null : $created[$item['parent']]->id,
                    'solution_id' => $item['solution']->id,
                    'kind' => $item['kind'],
                    'product_tier_id' => $item['addon'] ? null : $item['tier']->id,
                    'product_addon_id' => $item['addon']?->id,
                    'description' => $item['description'],
                    'sac_code' => $item['sac'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'discount' => $item['discount'],
                    'terms' => $item['terms'],
                    'sort_order' => $index,
                ]);
            }

            foreach ($quote->coupons as $coupon) {
                if ($coupon['refusal'] === null && $coupon['discount'] > 0) {
                    CouponRedemption::query()->create([
                        'coupon_id' => $coupon['coupon']->id,
                        'order_id' => $order->id,
                        'client_id' => $client->id,
                        'amount' => $coupon['discount'],
                        'status' => 'held',
                    ]);
                }
            }

            $payment = PaymentRequest::query()->create([
                'user_id' => $client->id,
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'title' => "Order {$order->number}",
                'description' => $order->items->whereNull('parent_id')->pluck('description')->implode('; '),
                'subtotal' => $quote->taxable(),
                'tax_rate' => $quote->taxRate,
                'tax' => $quote->tax,
                'total' => $quote->total(),
                'due_on' => today()->addDays((int) config('company.shop.order_expiry_days')),
                'raised_by' => $buyer->id,
                'status' => 'pending',
            ]);

            $order->forceFill(['payment_request_id' => $payment->id])->save();

            return $order;
        });

        $this->carts->clear($buyer);
        $this->rememberBillingDetails($buyer, $billing);
        $this->auditor->actingAs($buyer)->action('order.placed', $order, ['total' => $order->total], $order->number);

        return $order;
    }

    /**
     * Uses are counted with the codes locked, so the last use of a limited
     * code goes to one order, not to everybody who reached checkout at once.
     */
    protected function holdCoupons(Quote $quote, User $client): void
    {
        $applied = array_filter($quote->coupons, fn (array $coupon) => $coupon['refusal'] === null && $coupon['discount'] > 0);

        if ($applied === []) {
            return;
        }

        $locked = Coupon::query()->whereIn('id', array_map(fn (array $coupon) => $coupon['coupon']->id, $applied))->lockForUpdate()->get()->keyBy('id');

        foreach ($applied as $coupon) {
            $fresh = $locked->get($coupon['coupon']->id);
            $refusal = $fresh ? $this->coupons->refusal($fresh, $quote->subtotal, $coupon['discount'], $client) : 'This code is not valid.';

            if ($refusal !== null) {
                throw new InvalidArgumentException("{$coupon['code']}: {$refusal}");
            }
        }
    }

    /**
     * The account holder's own billing details fill gaps on their profile, so
     * the next order and the client's billing screen start from them. Nothing
     * already there is overwritten, and a member buying for an organisation
     * does not change the organisation's details.
     */
    protected function rememberBillingDetails(User $buyer, array $billing): void
    {
        if ($buyer->clientAccount()->id !== $buyer->id) {
            return;
        }

        $profile = $buyer->profile()->firstOrCreate([]);
        $fill = array_filter([
            'company' => $profile->company ? null : ($billing['name'] !== $buyer->name ? $billing['name'] : null),
            'gstin' => $profile->gstin ? null : ($billing['gstin'] ?? null),
            'state' => $profile->state ? null : $billing['state'],
            'address_line_1' => $profile->address_line_1 ? null : ($billing['address'] ?? null),
            'city' => $profile->city ? null : ($billing['city'] ?? null),
            'postal_code' => $profile->postal_code ? null : ($billing['postal_code'] ?? null),
        ]);

        if ($fill !== []) {
            $profile->forceFill($fill)->save();
        }
    }

    /**
     * The order's payment request is paid: deliver what was bought.
     *
     * Called from `Invoicer`, inside the transaction that recorded the money,
     * whichever way it came in. Safe to call twice.
     */
    public function fulfil(Order $order): void
    {
        $order = Order::query()->lockForUpdate()->findOrFail($order->id);

        if ($order->paid_at !== null) {
            return;
        }

        $order->forceFill(['status' => 'paid', 'paid_at' => now(), 'cancelled_at' => null, 'cancel_reason' => null])->save();

        $this->coupons->redeem($order);
        $this->licences->issueFor($order);
        $this->startSubscriptions($order);

        $this->auditor->action('order.paid', $order, ['total' => $order->total], $order->number);

        DB::afterCommit(function () use ($order) {
            $order->refresh()->load('items', 'client', 'buyer');

            Notification::send(
                collect([$order->buyer])->merge($order->client->clientRecipients('billing'))->unique('id')->filter(fn (?User $user) => $user?->canSignIn()),
                new OrderNotice($order, 'paid'),
            );

            Notification::send(User::query()->holdingPermission('orders.view')->active()->get(), new OrderNotice($order, 'received'));
        });
    }

    /** A hosted plan starts the day it is paid for, and renews itself. */
    protected function startSubscriptions(Order $order): void
    {
        foreach ($order->items()->where('kind', 'plan')->get() as $item) {
            if (Subscription::query()->where('order_item_id', $item->id)->exists()) {
                continue;
            }

            $interval = $item->terms['interval'] ?? 'yearly';

            Subscription::query()->create([
                'client_id' => $order->client_id,
                'subscribable_type' => Solution::class,
                'subscribable_id' => $item->solution_id,
                'order_item_id' => $item->id,
                'name' => $item->description,
                'description' => "Started by order {$order->number}.",
                'amount' => $item->unit_price,
                'interval' => $interval,
                'starts_on' => today(),
                'renews_on' => $interval === 'monthly' ? today()->addMonthNoOverflow() : today()->addYearNoOverflow(),
                'auto_renew' => true,
                'status' => 'active',
            ]);
        }
    }

    /**
     * Call off an order nobody has paid for.
     *
     * Not while a payment may still be on its way: an attempt started in the
     * last half hour could yet succeed, and money for a cancelled order is a
     * refund nobody wanted.
     */
    public function cancel(Order $order, string $reason, ?User $by = null): void
    {
        DB::transaction(function () use ($order, $reason, $by) {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->isPending()) {
                throw new InvalidArgumentException('Only an order waiting for payment can be cancelled.');
            }

            $recent = Transaction::query()
                ->where('payment_request_id', $locked->payment_request_id)
                ->where('status', 'created')
                ->where('created_at', '>', now()->subMinutes(30))
                ->exists();

            if ($recent && $by !== null) {
                throw new InvalidArgumentException('A payment for this order was started a moment ago and may still go through. Try again in half an hour.');
            }

            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $this->coupons->release($locked);

            $payment = $locked->paymentRequest;

            if ($payment?->status === 'pending') {
                $payment->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
                $payment->invoice?->forceFill(['status' => 'cancelled'])->save();
            }

            $this->auditor->actingAs($by)->action('order.cancelled', $locked, ['reason' => $reason], $locked->number);
        });
    }

    /** Orders left unpaid past their date lapse, and their codes are freed. */
    public function expire(): int
    {
        $count = 0;

        Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subDays((int) config('company.shop.order_expiry_days')))
            ->each(function (Order $order) use (&$count) {
                $this->cancel($order, 'Not paid in time.');
                $count++;
            });

        return $count;
    }

    /**
     * A refund on an order's payment has gone through.
     *
     * The whole amount back withdraws everything the order gave: its licences
     * stop verifying and downloading, and its plans stop renewing. Part of it
     * back (an add-on not delivered, say) changes nothing by itself; staff
     * withdraw a licence by hand if that is what was agreed.
     */
    public function refundSettled(Transaction $transaction, ?User $by = null): void
    {
        $order = $transaction->paymentRequest?->payable;

        if (! $order instanceof Order || ! $order->isPaid()) {
            return;
        }

        $paid = (int) Transaction::query()->where('payment_request_id', $order->payment_request_id)->whereIn('status', ['successful', 'refunded'])->sum('amount');
        $refunded = (int) Transaction::query()->where('payment_request_id', $order->payment_request_id)->get()->sum(fn (Transaction $t) => $t->refundedAmount());

        if ($refunded < $paid) {
            $order->forceFill(['status' => 'partially_refunded'])->save();

            return;
        }

        $order->forceFill(['status' => 'refunded'])->save();

        foreach (Licence::query()->where('order_id', $order->id)->get() as $licence) {
            $this->licences->revoke($licence, "Order {$order->number} was refunded.", $by);
        }

        Subscription::query()
            ->whereIn('order_item_id', $order->items()->pluck('id'))
            ->where('status', 'active')
            ->get()
            ->each(fn (Subscription $subscription) => $subscription->forceFill(['status' => 'cancelled', 'auto_renew' => false, 'ends_on' => today()])->save());

        $this->auditor->actingAs($by)->action('order.refunded', $order, label: $order->number);
    }

    /** Whether this person may pay this order: they placed it, or they pay for the organisation. */
    public static function mayPay(Order $order, User $user): bool
    {
        return $order->user_id === $user->id || $user->canAsClient('pay');
    }
}
