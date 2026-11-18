<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Something bought from the cart (§4).
 *
 * Priced on the server when it is placed, and frozen: what was sold, at what
 * price, with which codes, billed to whom. It is paid through a payment
 * request like everything else, and invoiced the same way.
 */
class Order extends Model
{
    public const STATUSES = [
        'pending' => 'Waiting for payment',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'partially_refunded' => 'Partly refunded',
    ];

    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['status' => 'pending', 'discount' => 0];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->number ??= self::nextNumber();
        });
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'taxable' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'tax_rate' => 'decimal:2',
            'tax_breakup' => 'array',
            'billing' => 'array',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function payment(): MorphOne
    {
        return $this->morphOne(PaymentRequest::class, 'payable');
    }

    public function scopeForClient(Builder $query, User|int $client): Builder
    {
        return $query->where('client_id', $client instanceof User ? $client->id : $client);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'partially_refunded'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function totalLabel(): string
    {
        return Money::display($this->total);
    }

    /** "Hospital management system, and 2 more". */
    public function summary(): string
    {
        $names = $this->items->whereNull('parent_id')->map(fn (OrderItem $item) => $item->terms['product'] ?? $item->description)->unique()->values();

        return match ($names->count()) {
            0 => 'An order',
            1 => $names[0],
            default => $names[0].', and '.($names->count() - 1).' more',
        };
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'ORD-'.str_pad((string) ($number + 1), 5, '0', STR_PAD_LEFT);
    }
}
