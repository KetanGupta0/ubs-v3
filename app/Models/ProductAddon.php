<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something bought alongside a product: work we deliver (installation,
 * customisation hours), or months of support or updates added to a licence.
 */
class ProductAddon extends Model
{
    public const KINDS = [
        'service' => 'Work we deliver',
        'support' => 'Months of support on the licence',
        'updates' => 'Months of updates on the licence',
    ];

    public const UNITS = ['hour', 'month'];

    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['kind' => 'service', 'is_active' => true, 'max_quantity' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'max_quantity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function solution(): BelongsTo
    {
        return $this->belongsTo(Solution::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Support or update months only mean something on a licence. */
    public function needsLicence(): bool
    {
        return in_array($this->kind, ['support', 'updates'], true);
    }

    /** @return array<string, mixed> */
    public function toOffer(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind,
            'price' => Money::price($this->price),
            'pricePaise' => $this->price,
            'unit' => $this->unit,
            'maxQuantity' => max(1, $this->max_quantity),
            'needsLicence' => $this->needsLicence(),
        ];
    }
}
