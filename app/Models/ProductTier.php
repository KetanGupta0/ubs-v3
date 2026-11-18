<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A licence tier (the source code, at a fixed price) or a hosted plan.
 *
 * The price is before tax (X17). Whatever an order needs from here is copied
 * onto the order, so repricing or retiring a tier never changes a sale.
 */
class ProductTier extends Model
{
    public const KINDS = ['licence', 'plan'];

    public const INTERVALS = ['monthly' => 'month', 'yearly' => 'year'];

    protected $guarded = ['id'];

    /** The column defaults, on the instance too: a default is never written back to the model that inserted the row. */
    protected $attributes = ['is_active' => true, 'update_months' => 12, 'support_months' => 0, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'sites' => 'integer',
            'update_months' => 'integer',
            'support_months' => 'integer',
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

    public function isLicence(): bool
    {
        return $this->kind === 'licence';
    }

    public function sitesLabel(): string
    {
        return match (true) {
            $this->sites === null => 'Unlimited sites',
            $this->sites === 1 => 'One site',
            default => "Up to {$this->sites} sites",
        };
    }

    /** @return array<int, string> What the tier includes, in words. */
    public function terms(): array
    {
        if (! $this->isLicence()) {
            return ['Billed every '.(self::INTERVALS[$this->interval] ?? 'period').', renews until cancelled'];
        }

        return array_values(array_filter([
            $this->sitesLabel(),
            $this->update_months > 0 ? self::months($this->update_months).' of updates' : 'The version bought, without updates',
            $this->support_months > 0 ? self::months($this->support_months).' of support' : null,
        ]));
    }

    /** "Single site licence" or "Business plan", as it is written on an order. */
    public function lineName(): string
    {
        return $this->isLicence()
            ? (str($this->name)->lower()->contains('licence') ? $this->name : "{$this->name} licence")
            : (str($this->name)->lower()->contains('plan') ? $this->name : "{$this->name} plan");
    }

    /** @return array<string, mixed> */
    public function toOffer(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'description' => $this->description,
            'price' => Money::price($this->price),
            // For the page's running sum only; the cart prices it on the server.
            'pricePaise' => $this->price,
            'per' => $this->isLicence() ? null : (self::INTERVALS[$this->interval] ?? null),
            'terms' => $this->terms(),
        ];
    }

    public static function months(int $months): string
    {
        return match (true) {
            $months % 12 === 0 && $months >= 12 => ($months === 12 ? 'One year' : ($months / 12).' years'),
            $months === 1 => 'One month',
            default => "{$months} months",
        };
    }
}
