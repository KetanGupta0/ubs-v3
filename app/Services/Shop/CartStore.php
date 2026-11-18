<?php

namespace App\Services\Shop;

use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;

/**
 * Where a cart is kept: in the session for a guest, on the account once
 * somebody signs in (§4). It holds choices only. Nothing in here is a price,
 * so nothing in here can be trusted or go stale; `Pricing` works out what it
 * costs every time.
 */
class CartStore
{
    public const SESSION_KEY = 'cart';

    public const MAX_LINES = 20;

    public const MAX_COUPONS = 3;

    /** @return array{lines: array<int, array{solution: int, tier: int, addons: array<int, array{id: int, quantity: int}>}>, coupons: array<int, string>} */
    public function contents(?User $user): array
    {
        $raw = $user
            ? Cart::query()->where('user_id', $user->id)->first()?->only(['lines', 'coupons'])
            : session(self::SESSION_KEY);

        return $this->normalise($raw ?? []);
    }

    public function count(?User $user): int
    {
        return count($this->contents($user)['lines']);
    }

    /** Put a product in the cart, or change the tier and add-ons it is in there with. */
    public function add(?User $user, int $solution, int $tier, array $addons = []): void
    {
        $contents = $this->contents($user);
        $line = ['solution' => $solution, 'tier' => $tier, 'addons' => $this->addonList($addons)];

        $index = collect($contents['lines'])->search(fn (array $existing) => $existing['solution'] === $solution);

        if ($index === false) {
            $contents['lines'][] = $line;
        } else {
            $contents['lines'][$index] = $line;
        }

        $contents['lines'] = array_slice($contents['lines'], -self::MAX_LINES);
        $this->save($user, $contents);
    }

    public function remove(?User $user, int $solution): void
    {
        $contents = $this->contents($user);
        $contents['lines'] = array_values(array_filter($contents['lines'], fn (array $line) => $line['solution'] !== $solution));
        $this->save($user, $contents);
    }

    public function addCoupon(?User $user, string $code): void
    {
        $contents = $this->contents($user);
        $code = Coupon::normalise($code);

        if (! in_array($code, $contents['coupons'], true)) {
            $contents['coupons'] = array_slice([...$contents['coupons'], $code], -self::MAX_COUPONS);
        }

        $this->save($user, $contents);
    }

    public function removeCoupon(?User $user, string $code): void
    {
        $contents = $this->contents($user);
        $contents['coupons'] = array_values(array_diff($contents['coupons'], [Coupon::normalise($code)]));
        $this->save($user, $contents);
    }

    /**
     * Make the cart what its price says can be sold: lines and add-ons no
     * longer on sale are dropped, quantities capped. The notice about it is
     * shown once, rather than on every visit.
     */
    public function keepOnly(?User $user, Quote $quote): void
    {
        $contents = $this->contents($user);
        $lines = [];

        foreach ($quote->items as $item) {
            if ($item['parent'] === null) {
                $lines[] = ['solution' => $item['solution']->id, 'tier' => $item['tier']->id, 'addons' => []];

                continue;
            }

            $lines[array_key_last($lines)]['addons'][] = ['id' => $item['addon']->id, 'quantity' => $item['quantity']];
        }

        $contents['lines'] = $lines;
        $this->save($user, $contents);
    }

    public function clear(?User $user): void
    {
        $this->save($user, ['lines' => [], 'coupons' => []]);
    }

    /**
     * Bring a guest's cart onto the account they have just signed in to.
     *
     * What they chose as a guest is what they were looking at a moment ago,
     * so it wins over an older line for the same product; codes are added to
     * whatever the account already had.
     */
    public function mergeGuestCartInto(User $user): void
    {
        $guest = $this->normalise(session(self::SESSION_KEY) ?? []);

        if ($guest['lines'] === [] && $guest['coupons'] === []) {
            return;
        }

        $mine = $this->contents($user);

        foreach ($guest['lines'] as $line) {
            $mine['lines'] = array_values(array_filter($mine['lines'], fn (array $existing) => $existing['solution'] !== $line['solution']));
            $mine['lines'][] = $line;
        }

        $mine['lines'] = array_slice($mine['lines'], -self::MAX_LINES);
        $mine['coupons'] = array_slice(array_values(array_unique([...$mine['coupons'], ...$guest['coupons']])), -self::MAX_COUPONS);

        $this->save($user, $mine);
        session()->forget(self::SESSION_KEY);
    }

    protected function save(?User $user, array $contents): void
    {
        $contents = $this->normalise($contents);

        if ($user) {
            Cart::query()->updateOrCreate(['user_id' => $user->id], $contents);

            return;
        }

        session()->put(self::SESSION_KEY, $contents);
    }

    /** Whatever was stored, reduced to whole numbers and known keys. */
    protected function normalise(array $raw): array
    {
        $lines = collect($raw['lines'] ?? [])
            ->filter(fn ($line) => is_array($line) && (int) ($line['solution'] ?? 0) > 0 && (int) ($line['tier'] ?? 0) > 0)
            ->map(fn (array $line) => [
                'solution' => (int) $line['solution'],
                'tier' => (int) $line['tier'],
                'addons' => $this->addonList($line['addons'] ?? []),
            ])
            ->unique('solution')
            ->values()
            ->all();

        $coupons = collect($raw['coupons'] ?? [])
            ->filter(fn ($code) => is_string($code) && $code !== '')
            ->map(fn (string $code) => Coupon::normalise($code))
            ->unique()
            ->values()
            ->all();

        return ['lines' => $lines, 'coupons' => $coupons];
    }

    /** @return array<int, array{id: int, quantity: int}> */
    protected function addonList(mixed $addons): array
    {
        return collect(is_array($addons) ? $addons : [])
            ->map(fn ($addon) => [
                'id' => (int) (is_array($addon) ? ($addon['id'] ?? 0) : 0),
                'quantity' => (int) (is_array($addon) ? ($addon['quantity'] ?? 1) : 1),
            ])
            ->filter(fn (array $addon) => $addon['id'] > 0 && $addon['quantity'] > 0)
            ->unique('id')
            ->values()
            ->all();
    }
}
