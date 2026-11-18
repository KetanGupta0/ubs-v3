<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Solution;
use App\Models\User;
use App\Services\Shop\CartStore;
use App\Services\Shop\Orders;
use App\Services\Shop\Pricing;
use App\Support\IndianStates;
use App\Support\Money;
use App\Support\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * The cart (§4): open to anybody, kept in the session until they sign in and
 * on the account after. Every figure on it comes from `Pricing`, the same sum
 * the order is made from; the page only ever says what it wants.
 */
class CartController extends Controller
{
    public function show(Request $request, CartStore $carts, Pricing $pricing): Response
    {
        $user = $request->user();
        $buyer = $user?->isClient() ? $user : null;
        $billing = $this->billingDefaults($buyer);

        $state = $request->query('state');
        $state = is_string($state) && in_array($state, IndianStates::CODES, true) ? $state : $billing['state'];

        $quote = $pricing->quote($carts->contents($user), $buyer?->clientAccount(), $state ?: null);

        if ($quote->notices !== []) {
            $carts->keepOnly($user, $quote);
        }

        return Inertia::render('shop/Cart', [
            'quote' => $quote->toScreen(),
            'state' => $state,
            'states' => IndianStates::options(),
            'billing' => $billing,
            'buyer' => $user ? [
                'name' => $user->name,
                'email' => $user->email,
                'canBuy' => $user->isClient(),
                'organisation' => $buyer?->organisationName(),
            ] : null,
            'checkoutBuyer' => $user ? null : $request->session()->get('checkout.buyer'),
            'seo' => [...Seo::for('Your cart', 'The products you have chosen, with GST worked out for your state.', '/cart'), 'noindex' => true],
        ]);
    }

    /** Put a product in the cart, or change how it is in there. */
    public function add(Request $request, CartStore $carts): RedirectResponse
    {
        $validated = $request->validate([
            'solution' => ['required', 'integer'],
            'tier' => ['required', 'integer'],
            'addons' => ['array', 'max:20'],
            'addons.*.id' => ['required', 'integer'],
            'addons.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
        ]);

        // Only what the site actually sells. Everything else about the line
        // (the tier is on sale, the add-ons belong to it) is the price's job.
        $solution = Solution::query()->published()->with('tiers')->find($validated['solution']);
        $tier = $solution?->tiers->firstWhere('id', $validated['tier']);

        abort_unless($tier !== null && $tier->is_active && $solution->offers($tier->isLicence() ? 'source_code' : 'subscription'), 404);

        $carts->add($request->user(), $solution->id, $tier->id, $validated['addons'] ?? []);

        return redirect('/cart')->with('success', "{$solution->title} is in your cart.");
    }

    public function remove(Request $request, int $solution, CartStore $carts): RedirectResponse
    {
        $carts->remove($request->user(), $solution);

        return back()->with('success', 'Taken out of your cart.');
    }

    /**
     * Apply a code, if it applies. A code that does not is not kept, and the
     * person is told why, then and there.
     */
    public function applyCoupon(Request $request, CartStore $carts, Pricing $pricing): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $code = Coupon::normalise($validated['code']);
        $user = $request->user();

        $contents = $carts->contents($user);
        $contents['coupons'] = array_values(array_unique([...$contents['coupons'], $code]));
        $buyer = $user?->isClient() ? $user->clientAccount() : null;

        $result = collect($pricing->quote($contents, $buyer, null)->coupons)->firstWhere('code', $code);

        if ($result === null || $result['refusal'] !== null) {
            throw ValidationException::withMessages(['code' => $result['refusal'] ?? 'This code is not valid.']);
        }

        $carts->addCoupon($user, $code);

        return back()->with('success', "{$code} applied: ".Money::display($result['discount']).' off.');
    }

    public function removeCoupon(Request $request, string $code, CartStore $carts): RedirectResponse
    {
        $carts->removeCoupon($request->user(), $code);

        return back();
    }

    /**
     * Place the order and go to pay for it. Only a client account buys; the
     * billing details are checked the way the billing screen checks them,
     * and the total the buyer was shown must still be the total.
     */
    public function order(Request $request, Orders $orders): RedirectResponse
    {
        $user = $request->user();

        if (! $user->isClient()) {
            return back()->with('error', 'Products are bought on a client account. Sign out, and buy with the address you use for work.');
        }

        $request->merge(['gstin' => strtoupper(str_replace(' ', '', (string) $request->input('gstin')))]);

        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:160'],
            'gstin' => ['nullable', 'string', 'regex:'.IndianStates::GSTIN_PATTERN],
            'state' => ['required', Rule::in(array_values(IndianStates::CODES))],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
            'expected_total' => ['required', 'integer', 'min:0'],
            'accept_terms' => ['accepted'],
        ], [
            'name.required' => 'The name to put on the invoice: your organisation\'s legal name, or yours.',
            'gstin.regex' => 'That is not the shape of a GSTIN: 15 characters, such as 23ABCDE1234F1Z5.',
            'state.required' => 'Choose your state. It decides how GST is charged.',
            'postal_code.regex' => 'A PIN code is six digits.',
            'accept_terms.accepted' => 'Agree to the terms and the refund policy to place the order.',
        ]);

        if ($refusal = IndianStates::gstinRefusal($validated['gstin'], $validated['state'])) {
            throw ValidationException::withMessages(['gstin' => $refusal]);
        }

        try {
            $order = $orders->place($user, [
                'name' => trim($validated['name']),
                'gstin' => $validated['gstin'] ?: null,
                'state' => $validated['state'],
                'address' => $validated['address'],
                'city' => $validated['city'],
                'postal_code' => $validated['postal_code'],
            ], (int) $validated['expected_total']);
        } catch (InvalidArgumentException $e) {
            return redirect('/cart?state='.urlencode($validated['state']))->with('error', $e->getMessage());
        }

        return redirect("/client/payments/{$order->payment_request_id}")
            ->with('success', "Order {$order->number} is placed. Pay for it here; the invoice is ready to download.");
    }

    /** @return array{name: string, gstin: ?string, state: ?string, address: ?string, city: ?string, postal_code: ?string} */
    protected function billingDefaults(?User $buyer): array
    {
        $account = $buyer?->clientAccount();
        $profile = $account?->profile;

        return [
            'name' => $profile?->company ?: ($account?->name ?? ''),
            'gstin' => $profile?->gstin,
            'state' => $profile?->state,
            'address' => $profile?->address_line_1,
            'city' => $profile?->city,
            'postal_code' => $profile?->postal_code,
        ];
    }
}
