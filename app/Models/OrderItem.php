<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of an order, as it was sold: the description, the price and the
 * terms are copied, so nothing done to the catalogue later changes it.
 */
class OrderItem extends Model
{
    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['quantity' => 1, 'discount' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'amount' => 'integer',
            'discount' => 'integer',
            'terms' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function taxable(): int
    {
        return $this->amount - $this->discount;
    }
}
