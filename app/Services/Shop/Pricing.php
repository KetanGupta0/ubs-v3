<?php

namespace App\Services\Shop;

use App\Models\Coupon;
use App\Models\ProductAddon;
use App\Models\Solution;
use App\Models\User;
use App\Services\Billing\Invoicer;
use Illuminate\Support\Str;

/**
 * What a cart costs (§4), from the catalogue as it stands now.
 *
 * Prices are before tax (X17). Codes are applied in the order they were added,
 * each on what is left of the lines it applies to, so two codes can never take
 * more than a line is worth. Each line carries its share of the discount, and
 * GST is worked out once on the taxable total, split CGST and SGST or IGST by
 * the place of supply, exactly as the invoice will be.
 */
class Pricing
{
    /** Licensing the right to use software. */
    public const LICENCE_SAC = '997331';

    /** Hosting and IT infrastructure provisioning. */
    public const PLAN_SAC = '998315';

    /** IT design and development. */
    public const SERVICE_SAC = '998314';

    public function __construct(
        protected Coupons $coupons,
        protected Invoicer $invoicer,
    ) {}

    /**
     * @param  array{lines: array<int, array{solution: int, tier: int, addons: array<int, array{id: int, quantity: int}>}>, coupons: array<int, string>}  $contents
     * @param  User|null  $client  The organisation buying, once known; a guest's cart is priced without the per account rules.
     */
    public function quote(array $contents, ?User $client, ?string $placeOfSupply): Quote
    {
        [$items, $notices] = $this->items($contents['lines']);
        $subtotal = array_sum(array_column($items, 'amount'));

        $applied = [];
        $results = [];

        foreach ($contents['coupons'] as $code) {
            $coupon = Coupon::findByCode($code);

            if (! $coupon) {
                $results[] = ['code' => $code, 'coupon' => null, 'discount' => 0, 'refusal' => 'This code is not valid.'];

                continue;
            }

            $eligible = array_keys(array_filter($items, fn (array $item) => $coupon->appliesTo($item['solution'])));
            $base = array_sum(array_map(fn (int $index) => $items[$index]['amount'] - $items[$index]['discount'], $eligible));

            $refusal = $this->coupons->refusal($coupon, $subtotal, $base, $client) ?? $this->stackingRefusal($coupon, $applied);

            if ($refusal !== null) {
                $results[] = ['code' => $coupon->code, 'coupon' => $coupon, 'discount' => 0, 'refusal' => $refusal];

                continue;
            }

            $discount = $this->coupons->discountOn($coupon, $base);
            $items = $this->allocate($items, $eligible, $discount);

            $applied[] = $coupon;
            $results[] = ['code' => $coupon->code, 'coupon' => $coupon, 'discount' => $discount, 'refusal' => null];
        }

        $discount = array_sum(array_column($items, 'discount'));
        $rate = $this->invoicer->defaultTaxRate();
        $breakup = $this->invoicer->gst()->for($subtotal - $discount, $rate, $placeOfSupply);

        return new Quote($items, $results, $subtotal, $discount, $rate, $breakup['components'], $breakup['total'], $placeOfSupply, $notices);
    }

    /**
     * Lines as they can be sold today. Anything no longer on sale is taken out
     * and said so, rather than sold at a price nobody is offering any more.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    protected function items(array $lines): array
    {
        $solutions = Solution::query()
            ->published()
            ->with('tiers', 'addons')
            ->whereIn('id', array_column($lines, 'solution'))
            ->get()
            ->keyBy('id');

        $items = [];
        $notices = [];

        foreach ($lines as $line) {
            $solution = $solutions->get($line['solution']);
            $tier = $solution?->tiers->firstWhere('id', $line['tier']);
            $offering = $tier?->isLicence() ? 'source_code' : 'subscription';

            if (! $solution || ! $tier || ! $tier->is_active || ! $solution->offers($offering)) {
                $notices[] = $solution
                    ? "{$solution->title}: that option is no longer on sale, so it was taken out of your cart."
                    : 'Something in your cart is no longer on sale, so it was taken out.';

                continue;
            }

            $parent = count($items);
            $items[] = [
                'kind' => $tier->kind,
                'solution' => $solution,
                'tier' => $tier,
                'addon' => null,
                'parent' => null,
                'description' => "{$solution->title}, {$tier->lineName()}",
                'sac' => $tier->isLicence() ? ($solution->sac_code ?: self::LICENCE_SAC) : self::PLAN_SAC,
                'quantity' => 1,
                'unit_price' => $tier->price,
                'amount' => $tier->price,
                'discount' => 0,
                'terms' => $tier->isLicence()
                    ? ['product' => $solution->title, 'tier' => $tier->name, 'sites' => $tier->sites, 'update_months' => $tier->update_months, 'support_months' => $tier->support_months]
                    : ['product' => $solution->title, 'tier' => $tier->name, 'interval' => $tier->interval],
            ];

            foreach ($line['addons'] as $chosen) {
                /** @var ProductAddon|null $addon */
                $addon = $solution->addons->firstWhere('id', $chosen['id']);

                if (! $addon || ! $addon->is_active) {
                    $notices[] = "{$solution->title}: an add-on is no longer offered with it, so it was left out.";

                    continue;
                }

                if ($addon->needsLicence() && ! $tier->isLicence()) {
                    $notices[] = "{$solution->title}: {$addon->name} goes with a licence, not a plan, so it was left out.";

                    continue;
                }

                $quantity = min(max(1, $chosen['quantity']), max(1, $addon->max_quantity));

                $items[] = [
                    'kind' => 'addon',
                    'solution' => $solution,
                    'tier' => $tier,
                    'addon' => $addon,
                    'parent' => $parent,
                    'description' => "{$solution->title}: {$addon->name}",
                    'label' => $addon->name.($addon->unit ? ', '.$quantity.' '.Str::plural($addon->unit, $quantity) : ''),
                    'sac' => $addon->sac_code ?: self::SERVICE_SAC,
                    'quantity' => $quantity,
                    'unit_price' => $addon->price,
                    'amount' => $addon->price * $quantity,
                    'discount' => 0,
                    'terms' => ['product' => $solution->title, 'kind' => $addon->kind, 'unit' => $addon->unit],
                ];
            }
        }

        return [$items, array_values(array_unique($notices))];
    }

    /** Codes are not combined unless every one of them says it may be. */
    protected function stackingRefusal(Coupon $coupon, array $applied): ?string
    {
        if ($applied === []) {
            return null;
        }

        $blocking = $coupon->stackable ? collect($applied)->first(fn (Coupon $other) => ! $other->stackable) : $applied[0];

        return $blocking ? "This code cannot be combined with {$blocking->code}." : null;
    }

    /**
     * Share a discount across the lines it applies to, in proportion to what
     * is left of each, to the paisa: the odd paise go to the lines with the
     * largest remainders, so the shares always add back to the discount.
     *
     * @param  array<int, int>  $indexes
     */
    protected function allocate(array $items, array $indexes, int $discount): array
    {
        $base = array_sum(array_map(fn (int $index) => $items[$index]['amount'] - $items[$index]['discount'], $indexes));

        if ($discount <= 0 || $base <= 0) {
            return $items;
        }

        $shares = [];
        $remainders = [];

        foreach ($indexes as $index) {
            $exact = $discount * ($items[$index]['amount'] - $items[$index]['discount']) / $base;
            $shares[$index] = (int) floor($exact);
            $remainders[$index] = $exact - $shares[$index];
        }

        $left = $discount - array_sum($shares);
        arsort($remainders);

        foreach (array_keys($remainders) as $index) {
            if ($left <= 0) {
                break;
            }

            $shares[$index]++;
            $left--;
        }

        foreach ($shares as $index => $share) {
            $items[$index]['discount'] += $share;
        }

        return $items;
    }
}
