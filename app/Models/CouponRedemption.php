<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One use of a code, on one order: held while the order waits for payment,
 * redeemed when it is paid, released if it lapses or is cancelled.
 */
class CouponRedemption extends Model
{
    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['status' => 'held'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Uses that count against a limit: redeemed, or held for an order placed
     * recently enough that it may still be paid. A hold on an order nobody
     * paid for stops counting after a while, without anything having to run.
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', 'redeemed')
            ->orWhere(fn (Builder $held) => $held
                ->where('status', 'held')
                ->where('created_at', '>', now()->subMinutes((int) config('company.shop.coupon_hold_minutes')))));
    }
}
