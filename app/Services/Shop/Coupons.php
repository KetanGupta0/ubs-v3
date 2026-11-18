<?php

namespace App\Services\Shop;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;

/**
 * The rules a discount code is held to (§4), in one place.
 *
 * A code is checked when the cart is priced and again, with the coupon row
 * locked, when the order is placed: the page never decides what a code is
 * worth. Limits count uses that are redeemed, and uses held for an order that
 * may still be paid.
 */
class Coupons
{
    /**
     * Why this code cannot be used, or null when it can.
     *
     * A guest's cart is checked without the rules that need an account (uses
     * per account, first order only); those are checked when the order is
     * placed, by which time everybody has signed in.
     *
     * @param  int  $subtotal  The cart's subtotal before any discount, in paise.
     * @param  int  $eligible  What the code could apply to in this cart, in paise.
     */
    public function refusal(Coupon $coupon, int $subtotal, int $eligible, ?User $client, ?int $exceptOrder = null): ?string
    {
        if (! $coupon->is_active) {
            return 'This code is not valid.';
        }

        if ($coupon->starts_at?->isFuture()) {
            return 'This code can be used from '.$coupon->starts_at->format('j M Y').'.';
        }

        if ($coupon->ends_at?->isPast()) {
            return 'This code has expired.';
        }

        if ($coupon->min_order && $subtotal < $coupon->min_order) {
            return 'This code needs an order of at least '.Money::price($coupon->min_order).' before tax.';
        }

        if ($eligible <= 0) {
            return 'This code does not apply to anything in your cart.';
        }

        if ($coupon->max_uses !== null && $this->uses($coupon, $exceptOrder) >= $coupon->max_uses) {
            return 'This code has been used up.';
        }

        if ($client) {
            if ($coupon->max_uses_per_account !== null && $this->uses($coupon, $exceptOrder, $client) >= $coupon->max_uses_per_account) {
                return 'You have already used this code.';
            }

            if ($coupon->first_order_only && Order::query()->forClient($client)->whereNotNull('paid_at')->exists()) {
                return 'This code is for a first order only.';
            }
        }

        return null;
    }

    /** What the code takes off what it applies to, never more than that. */
    public function discountOn(Coupon $coupon, int $eligible): int
    {
        $discount = $coupon->percent !== null
            ? (int) round($eligible * $coupon->percent / 100)
            : (int) $coupon->amount;

        if ($coupon->max_discount) {
            $discount = min($discount, $coupon->max_discount);
        }

        return max(0, min($discount, $eligible));
    }

    public function uses(Coupon $coupon, ?int $exceptOrder = null, ?User $client = null): int
    {
        return CouponRedemption::query()
            ->where('coupon_id', $coupon->id)
            ->counting()
            ->when($exceptOrder, fn ($q) => $q->where('order_id', '!=', $exceptOrder))
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->count();
    }

    /** The order was paid: its uses are spent. */
    public function redeem(Order $order): void
    {
        $order->redemptions()->whereIn('status', ['held', 'released'])->update(['status' => 'redeemed']);
    }

    /** The order lapsed or was cancelled: its uses are free again. */
    public function release(Order $order): void
    {
        $order->redemptions()->where('status', 'held')->update(['status' => 'released']);
    }
}
