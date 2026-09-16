<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An ask for money, raised by an administrator.
 *
 * The same row serves a client paying for a milestone and a student paying for
 * a course, because they are the same event with a different thing attached.
 */
class PaymentRequest extends Model
{
    public const STATUSES = ['pending', 'paid', 'cancelled', 'refunded'];

    protected $guarded = ['id'];

    /*
     * Column defaults again, in the model.
     *
     * A database default is applied on insert but never written back into the
     * instance that did the inserting, so code reading ->currency straight after
     * create() gets null. Declaring them here keeps the two in step.
     */
    protected $attributes = [
        'currency' => 'INR',
        'status' => 'pending',
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
        'tax_rate' => 18,
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentRequest $request) {
            $request->reference ??= self::nextReference();
        });
    }

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'tax_rate' => 'decimal:2',
            'reminders_sent' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /*
     * One direction only. The invoice knows which request it came from, and a
     * mirrored column on this side is one more thing that can disagree.
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->whereNotNull('due_on')->whereDate('due_on', '<', today());
    }

    public function isPayable(): bool
    {
        return $this->status === 'pending';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'pending' && $this->due_on !== null && $this->due_on->isPast();
    }

    public function totalLabel(): string
    {
        return Money::display($this->total);
    }

    /** Recompute from the subtotal and rate, so the stored total always agrees. */
    public function price(int $subtotal, float $taxRate): self
    {
        $tax = Money::taxOn($subtotal, $taxRate);

        $this->forceFill([
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);

        return $this;
    }

    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'PR-'.str_pad((string) ($number + 1), 5, '0', STR_PAD_LEFT);
    }
}
