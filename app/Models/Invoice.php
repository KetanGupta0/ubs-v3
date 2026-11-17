<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A numbered tax invoice.
 *
 * Who it was billed to and from is frozen into the row at issue. An invoice that
 * re-renders from today's settings is not a record of anything, it is a view of
 * current data wearing last year's number, and that is exactly what an auditor
 * will ask about.
 */
class Invoice extends Model
{
    public const STATUSES = ['issued', 'paid', 'partially_paid', 'cancelled', 'refunded'];

    protected $guarded = ['id'];

    protected $attributes = [
        'currency' => 'INR',
        'status' => 'issued',
        'subtotal' => 0,
        'discount' => 0,
        'tax' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'tds_amount' => 0,
        'credited_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'billed_to' => 'array',
            'billed_from' => 'array',
            'tax_breakup' => 'array',
            'issued_at' => 'datetime',
            'due_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', ['issued', 'partially_paid']);
    }

    /**
     * What is still owed: the total less what was paid, the TDS the client
     * deducted (CL14) and any credit notes against it.
     */
    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid - $this->tds_amount - $this->credited_amount);
    }

    /** The TDS the client declared on it, if any (CL14). */
    public function tdsDeduction(): HasOne
    {
        return $this->hasOne(TdsDeduction::class);
    }

    public function totalLabel(): string
    {
        return Money::display($this->total);
    }

    public function totalInWords(): string
    {
        return Money::words($this->total);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return ! $this->isPaid() && $this->due_on !== null && $this->due_on->isPast();
    }
}
