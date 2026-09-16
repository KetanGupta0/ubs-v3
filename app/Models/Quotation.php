<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The priced version of a proposal.
 *
 * Totals are stored rather than summed on read, so a line item edited a year
 * later cannot silently change what was quoted. `recalculate()` is the only
 * thing that writes them, and it runs when the items change.
 */
class Quotation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Quotation $quotation) {
            $quotation->number ??= self::nextNumber();
        });
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function recalculate(): self
    {
        $items = $this->items()->get();

        $subtotal = $items->sum('amount');
        $tax = $items->sum(fn (QuotationItem $item) => Money::taxOn($item->amount, (float) $item->tax_rate));

        $this->forceFill([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => max(0, $subtotal - $this->discount + $tax),
        ])->save();

        return $this;
    }

    public function totalLabel(): string
    {
        return Money::display($this->total);
    }

    public static function nextNumber(): string
    {
        $year = now()->year;
        $last = static::query()
            ->where('number', 'like', "UBS/Q/{$year}/%")
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? (int) str($last)->afterLast('/')->toString() : 0;

        return sprintf('UBS/Q/%d/%03d', $year, $sequence + 1);
    }
}
