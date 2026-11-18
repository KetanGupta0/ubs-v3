<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount code (§4).
 *
 * Checked and applied on the server when the cart is priced and again when the
 * order is placed, never taken from the page. Every use is recorded against
 * the order it was used on.
 */
class Coupon extends Model
{
    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['is_active' => true, 'stackable' => false, 'first_order_only' => false];

    protected function casts(): array
    {
        return [
            'percent' => 'integer',
            'amount' => 'integer',
            'max_discount' => 'integer',
            'min_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_uses' => 'integer',
            'max_uses_per_account' => 'integer',
            'solution_ids' => 'array',
            'category_ids' => 'array',
            'first_order_only' => 'boolean',
            'stackable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function normalise(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $code) ?? '');
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', self::normalise($code))->first();
    }

    /** Whether it is limited to some products or categories. */
    public function isRestricted(): bool
    {
        return ! empty($this->solution_ids) || ! empty($this->category_ids);
    }

    public function appliesTo(Solution $solution): bool
    {
        if (! $this->isRestricted()) {
            return true;
        }

        return in_array($solution->id, $this->solution_ids ?? [], true)
            || in_array($solution->solution_category_id, $this->category_ids ?? [], true);
    }

    /** "10% off, up to ₹5,000" or "₹2,000 off". */
    public function valueLabel(): string
    {
        if ($this->percent !== null) {
            return "{$this->percent}% off".($this->max_discount ? ', up to '.Money::price($this->max_discount) : '');
        }

        return Money::price((int) $this->amount).' off';
    }
}
