<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Solution;
use App\Models\SolutionCategory;
use App\Services\Admin\Auditor;
use App\Support\Money;
use App\Support\Table\Column;
use App\Support\Table\Filter;
use App\Support\Table\Table;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Discount codes (§4). Their rules are checked on the server when a cart is
 * priced and again when an order is placed; this screen only says what they
 * are. A code somebody has used keeps its name and stays on record: switch
 * it off rather than delete it, so an old order still says what it used.
 */
class CouponController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = Table::for(Coupon::query()->withCount(['redemptions as used' => fn ($q) => $q->where('status', 'redeemed')]))
            ->searchable(['code', 'description'])
            ->defaultSort('-created_at')
            ->exportName('discount-codes')
            ->columns([
                Column::make('code', 'Code')->sortable(),
                Column::make('value', 'Takes off'),
                Column::make('applies', 'On'),
                Column::make('used', 'Used')->numeric(),
                Column::make('window', 'When'),
                Column::make('state', 'State'),
            ])
            ->filters([
                Filter::boolean('is_active', 'Switched on only'),
            ])
            ->transform(fn (Coupon $coupon) => [
                ...$this->forForm($coupon),
                'value' => $coupon->valueLabel(),
                'applies' => $this->appliesLabel($coupon),
                'used' => $coupon->used.($coupon->max_uses ? " of {$coupon->max_uses}" : ''),
                'usedCount' => $coupon->used,
                'window' => $this->windowLabel($coupon),
                'state' => $this->stateOf($coupon),
            ]);

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/shop/Coupons', [
            'table' => $table->toArray($request),
            'solutions' => Solution::query()->whereHas('tiers')->orderBy('title')->get(['id', 'title'])->map(fn (Solution $solution) => ['value' => $solution->id, 'label' => $solution->title])->values(),
            'categories' => SolutionCategory::query()->orderBy('sort_order')->get(['id', 'name'])->map(fn (SolutionCategory $category) => ['value' => $category->id, 'label' => $category->name])->values(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $coupon = Coupon::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
        $auditor->created($coupon, $coupon->code);

        return back()->with('success', "{$coupon->code} is ready to use.");
    }

    public function update(Request $request, Coupon $coupon, Auditor $auditor): RedirectResponse
    {
        $coupon->fill($this->validated($request, $coupon));
        $auditor->updated($coupon, label: $coupon->code);
        $coupon->save();

        return back()->with('success', 'Saved. Orders already placed keep the discount they had.');
    }

    public function toggle(Coupon $coupon, Auditor $auditor): RedirectResponse
    {
        $coupon->is_active = ! $coupon->is_active;
        $auditor->updated($coupon, label: $coupon->code);
        $coupon->save();

        return back()->with('success', $coupon->is_active ? "{$coupon->code} is on." : "{$coupon->code} is off. Nobody can use it now.");
    }

    public function destroy(Coupon $coupon, Auditor $auditor): RedirectResponse
    {
        if ($coupon->redemptions()->exists()) {
            return back()->with('error', 'This code is on an order, so it stays on record. Switch it off instead.');
        }

        $auditor->deleted($coupon, $coupon->code);
        $coupon->delete();

        return back()->with('success', 'Deleted.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => Coupon::normalise((string) $request->input('code'))]);
        $used = $coupon && CouponRedemption::query()->where('coupon_id', $coupon->id)->exists();

        $validated = $this->validatedInput($request, [
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,40}$/', Rule::unique('coupons', 'code')->ignore($coupon?->id),
                fn ($attribute, $value, $fail) => $used && $value !== $coupon->code ? $fail('Somebody has used this code, so its name stays. Make a new code instead.') : null],
            'description' => ['nullable', 'string', 'max:200'],
            'kind' => ['required', Rule::in(['percent', 'amount'])],
            'percent' => ['nullable', 'required_if:kind,percent', 'integer', 'min:1', 'max:100'],
            'amount' => ['nullable', 'required_if:kind,amount', 'numeric', 'min:1', 'max:10000000'],
            'max_discount' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'min_order' => ['nullable', 'numeric', 'min:1', 'max:100000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_uses_per_account' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'solution_ids' => ['array'],
            'solution_ids.*' => ['integer', Rule::exists('solutions', 'id')],
            'category_ids' => ['array'],
            'category_ids.*' => ['integer', Rule::exists('solution_categories', 'id')],
            'first_order_only' => ['boolean'],
            'stackable' => ['boolean'],
            'is_active' => ['boolean'],
        ], [
            'code.regex' => 'Three to forty letters, digits, dashes or underscores.',
            'percent.required_if' => 'How much off, as a percentage.',
            'amount.required_if' => 'How much off, in rupees.',
            'ends_at.after' => 'It has to end after it starts.',
        ]);

        $percent = $validated['kind'] === 'percent';

        return [
            'code' => $validated['code'],
            'description' => $validated['description'],
            'percent' => $percent ? (int) $validated['percent'] : null,
            'amount' => $percent ? null : Money::toPaise($validated['amount']),
            // A cap only means something on a percentage.
            'max_discount' => $percent && $validated['max_discount'] ? Money::toPaise($validated['max_discount']) : null,
            'min_order' => $validated['min_order'] ? Money::toPaise($validated['min_order']) : null,
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'max_uses' => $validated['max_uses'],
            'max_uses_per_account' => $validated['max_uses_per_account'],
            'solution_ids' => array_values(array_map('intval', $validated['solution_ids'] ?? [])) ?: null,
            'category_ids' => array_values(array_map('intval', $validated['category_ids'] ?? [])) ?: null,
            'first_order_only' => $validated['first_order_only'],
            'stackable' => $validated['stackable'],
            'is_active' => $validated['is_active'],
        ];
    }

    /** @return array<string, mixed> What the edit dialog starts from. */
    protected function forForm(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'kind' => $coupon->percent !== null ? 'percent' : 'amount',
            'percent' => $coupon->percent,
            'amount' => $coupon->amount !== null ? $coupon->amount / 100 : null,
            'max_discount' => $coupon->max_discount !== null ? $coupon->max_discount / 100 : null,
            'min_order' => $coupon->min_order !== null ? $coupon->min_order / 100 : null,
            'starts_at' => $coupon->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $coupon->ends_at?->format('Y-m-d\TH:i'),
            'max_uses' => $coupon->max_uses,
            'max_uses_per_account' => $coupon->max_uses_per_account,
            'solution_ids' => $coupon->solution_ids ?? [],
            'category_ids' => $coupon->category_ids ?? [],
            'first_order_only' => $coupon->first_order_only,
            'stackable' => $coupon->stackable,
            'is_active' => $coupon->is_active,
        ];
    }

    protected function appliesLabel(Coupon $coupon): string
    {
        $parts = array_filter([
            count($coupon->solution_ids ?? []) ? count($coupon->solution_ids).' '.str('product')->plural(count($coupon->solution_ids)) : null,
            count($coupon->category_ids ?? []) ? count($coupon->category_ids).' '.str('category')->plural(count($coupon->category_ids)) : null,
        ]);

        return ($parts ? implode(' and ', $parts) : 'Everything sold').($coupon->first_order_only ? ', first order only' : '');
    }

    protected function windowLabel(Coupon $coupon): string
    {
        return match (true) {
            $coupon->starts_at && $coupon->ends_at => $coupon->starts_at->format('j M').' to '.$coupon->ends_at->format('j M Y'),
            (bool) $coupon->ends_at => 'Until '.$coupon->ends_at->format('j M Y'),
            (bool) $coupon->starts_at => 'From '.$coupon->starts_at->format('j M Y'),
            default => 'Any time',
        };
    }

    protected function stateOf(Coupon $coupon): string
    {
        return match (true) {
            ! $coupon->is_active => 'Off',
            $coupon->ends_at?->isPast() => 'Ended',
            $coupon->starts_at?->isFuture() => 'Not started',
            $coupon->max_uses !== null && $coupon->used >= $coupon->max_uses => 'Used up',
            default => 'On',
        };
    }
}
