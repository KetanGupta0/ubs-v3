<?php

namespace App\Services\Shop;

use App\Models\Coupon;
use App\Support\Money;

/**
 * What a cart costs, worked out on the server (§4).
 *
 * The cart page shows this and the order is made from this, so the two cannot
 * disagree: there is no second sum anywhere.
 */
final class Quote
{
    /**
     * @param  array<int, array<string, mixed>>  $items  Flat, each product line followed by its add-ons.
     * @param  array<int, array{code: string, coupon: ?Coupon, discount: int, refusal: ?string}>  $coupons
     * @param  array<int, array{label: string, rate: float, amount: int}>  $breakup
     * @param  array<int, string>  $notices  Things taken out of the cart, and why.
     */
    public function __construct(
        public readonly array $items,
        public readonly array $coupons,
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly float $taxRate,
        public readonly array $breakup,
        public readonly int $tax,
        public readonly ?string $placeOfSupply,
        public readonly array $notices,
    ) {}

    public function taxable(): int
    {
        return $this->subtotal - $this->discount;
    }

    public function total(): int
    {
        return $this->taxable() + $this->tax;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return array<int, array{code: string, refusal: string}> */
    public function refusedCoupons(): array
    {
        return array_values(array_map(
            fn (array $coupon) => ['code' => $coupon['code'], 'refusal' => $coupon['refusal']],
            array_filter($this->coupons, fn (array $coupon) => $coupon['refusal'] !== null),
        ));
    }

    /** @return array<string, mixed> */
    public function toScreen(): array
    {
        $lines = [];

        foreach ($this->items as $item) {
            $row = [
                'description' => $item['label'] ?? $item['description'],
                'quantity' => $item['quantity'],
                'unitPrice' => Money::price($item['unit_price']),
                'amount' => Money::display($item['amount']),
                'discount' => $item['discount'] > 0 ? Money::display($item['discount']) : null,
            ];

            if ($item['parent'] === null) {
                $lines[] = [
                    ...$row,
                    'solutionId' => $item['solution']->id,
                    'slug' => $item['solution']->slug,
                    'product' => $item['solution']->title,
                    'tierId' => $item['tier']->id,
                    'kind' => $item['kind'],
                    'terms' => $item['tier']->terms(),
                    'addons' => [],
                    'chosenAddons' => [],
                ];

                continue;
            }

            $lines[array_key_last($lines)]['addons'][] = $row;
            $lines[array_key_last($lines)]['chosenAddons'][] = ['id' => $item['addon']->id, 'quantity' => $item['quantity']];
        }

        return [
            'lines' => $lines,
            'coupons' => array_map(fn (array $coupon) => [
                'code' => $coupon['code'],
                'label' => $coupon['coupon']?->description ?: $coupon['coupon']?->valueLabel(),
                'discount' => $coupon['discount'] > 0 ? Money::display($coupon['discount']) : null,
                'refusal' => $coupon['refusal'],
            ], $this->coupons),
            'subtotal' => Money::display($this->subtotal),
            'discount' => $this->discount > 0 ? Money::display($this->discount) : null,
            'taxable' => Money::display($this->taxable()),
            'taxRate' => $this->taxRate,
            'breakup' => array_map(fn (array $part) => [
                'label' => $part['label'].' @ '.rtrim(rtrim(number_format((float) $part['rate'], 2), '0'), '.').'%',
                'amount' => Money::display($part['amount']),
            ], $this->breakup),
            'tax' => Money::display($this->tax),
            'total' => Money::display($this->total()),
            'totalPaise' => $this->total(),
            'placeOfSupply' => $this->placeOfSupply,
            'notices' => $this->notices,
        ];
    }
}
