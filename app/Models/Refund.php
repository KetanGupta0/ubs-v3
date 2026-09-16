<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Refund $refund) {
            $refund->reference ??= self::nextReference();
        });
    }

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function amountLabel(): string
    {
        return Money::display($this->amount);
    }

    public static function nextReference(): string
    {
        $last = static::query()->orderByDesc('id')->value('reference');
        $number = $last ? (int) str($last)->afterLast('-')->toString() : 0;

        return 'RFN-'.str_pad((string) ($number + 1), 5, '0', STR_PAD_LEFT);
    }
}
