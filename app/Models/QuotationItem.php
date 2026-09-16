<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // The line total is never typed in, so it cannot disagree with the
        // quantity and the rate shown next to it.
        static::saving(function (QuotationItem $item) {
            $item->amount = (int) round((float) $item->quantity * $item->unit_price);
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }
}
