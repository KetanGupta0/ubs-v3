<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a signed in person has chosen and not yet ordered.
 *
 * Only choices are kept: the product, the tier and the add-ons. Prices come
 * from the catalogue every time the cart is shown, so they cannot be stale.
 */
class Cart extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'coupons' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
