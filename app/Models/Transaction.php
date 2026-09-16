<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to pay.
 *
 * Failed attempts are kept. A ledger that only records successes cannot answer
 * "I tried three times and it kept failing", which is the question somebody
 * asks when they are already annoyed.
 */
class Transaction extends Model
{
    public const STATUSES = ['created', 'pending', 'successful', 'failed', 'refunded'];

    protected $guarded = ['id'];

    protected $hidden = ['gateway_signature'];

    protected $attributes = [
        'currency' => 'INR',
        'status' => 'created',
        'gateway' => 'razorpay',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            $transaction->reference ??= self::nextReference();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'successful');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function amountLabel(): string
    {
        return Money::display($this->amount);
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'successful';
    }

    public function refundedAmount(): int
    {
        return (int) $this->refunds()->where('status', 'processed')->sum('amount');
    }

    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'TXN-'.str_pad((string) ($number + 1), 6, '0', STR_PAD_LEFT);
    }
}
