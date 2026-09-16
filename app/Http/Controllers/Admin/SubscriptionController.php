<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\ApiKeyPlan;
use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
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
 * Renewals, and the API key plans clients buy against.
 *
 * Both live here because they are the same shape of thing: something a client
 * holds that expires unless somebody does something about it.
 */
class SubscriptionController extends Controller
{
    public function index(Request $request): Response|HttpResponse
    {
        $table = $this->table();

        if ($export = $table->exportResponse($request)) {
            return $export;
        }

        return Inertia::render('admin/subscriptions/Index', [
            'table' => $table->toArray($request),
            'counts' => [
                'active' => Subscription::query()->active()->count(),
                'renewingSoon' => Subscription::query()->renewingWithin(30)->count(),
                'lapsed' => Subscription::query()->active()->whereDate('renews_on', '<', today())->count(),
                'value' => Money::display((int) Subscription::query()->active()->sum('amount')),
            ],
            ...$this->options(),
        ]);
    }

    public function store(Request $request, Auditor $auditor): RedirectResponse
    {
        $subscription = Subscription::query()->create($this->validated($request));
        $auditor->created($subscription, $subscription->name);

        return back()->with('success', 'Subscription added.');
    }

    public function update(Request $request, Subscription $subscription, Auditor $auditor): RedirectResponse
    {
        $subscription->fill($this->validated($request));
        $auditor->updated($subscription, label: $subscription->name);
        $subscription->save();

        return back()->with('success', 'Subscription saved.');
    }

    /** Roll the renewal date forward by one interval. */
    public function renew(Subscription $subscription, Auditor $auditor): RedirectResponse
    {
        $next = $subscription->nextRenewalDate();

        $subscription->forceFill([
            'renews_on' => $next,
            'status' => 'active',
            // Reminder history belongs to the period that just ended.
            'reminders_sent' => [],
        ])->save();

        $auditor->action('subscription.renewed', $subscription, [
            'renews_on' => $next->toDateString(),
        ], $subscription->name);

        return back()->with('success', 'Renewed to '.$next->format('j M Y').'.');
    }

    public function cancel(Subscription $subscription, Auditor $auditor): RedirectResponse
    {
        $subscription->forceFill([
            'status' => 'cancelled',
            'auto_renew' => false,
            'ends_on' => today(),
        ])->save();

        $auditor->action('subscription.cancelled', $subscription, label: $subscription->name);

        return back()->with('success', 'Cancelled.');
    }

    /* ----------------------------------------------------------- API plans */

    public function plans(): Response
    {
        return Inertia::render('admin/subscriptions/Plans', [
            'plans' => ApiKeyPlan::query()
                ->withCount('keys')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ApiKeyPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'quota' => $plan->monthly_quota,
                    'rateLimit' => $plan->rate_limit_per_minute,
                    'price' => $plan->price / 100,
                    'priceLabel' => $plan->priceLabel(),
                    'interval' => $plan->interval,
                    'isActive' => $plan->is_active,
                    'keys' => $plan->keys_count,
                    'sortOrder' => $plan->sort_order,
                ]),
            'keys' => ApiKey::query()
                ->with(['client:id,name', 'plan:id,name'])
                ->latest('id')
                ->take(50)
                ->get()
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'label' => $key->label,
                    'masked' => $key->masked(),
                    'client' => $key->client?->name,
                    'plan' => $key->plan?->name,
                    'environment' => $key->environment,
                    'status' => $key->status,
                    'quotaUsed' => $key->quota_used,
                    'quota' => $key->quota(),
                    'lastUsedAt' => $key->last_used_at?->diffForHumans(),
                    'at' => $key->created_at->format('j M Y'),
                ]),
        ]);
    }

    public function storePlan(Request $request, Auditor $auditor): RedirectResponse
    {
        $plan = ApiKeyPlan::query()->create($this->planRules($request));
        $auditor->created($plan, $plan->name);

        return back()->with('success', 'Plan added.');
    }

    public function updatePlan(Request $request, ApiKeyPlan $plan, Auditor $auditor): RedirectResponse
    {
        $plan->fill($this->planRules($request, $plan));
        $auditor->updated($plan, label: $plan->name);
        $plan->save();

        return back()->with('success', 'Plan saved.');
    }

    public function revokeKey(Request $request, ApiKey $apiKey, Auditor $auditor): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        $apiKey->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => $validated['reason'],
        ])->save();

        $auditor->action('api_key.revoked', $apiKey, ['reason' => $validated['reason']], $apiKey->label);

        return back()->with('success', 'Key revoked. Anything using it stops working now.');
    }

    /* --------------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $validated = $this->validatedInput($request, [
            'client_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', Role::Client->value)],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'min:0'],
            'interval' => ['required', Rule::in(Subscription::INTERVALS)],
            'starts_on' => ['required', 'date'],
            'renews_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'auto_renew' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'subscribable_type' => ['nullable', Rule::in(['contract', 'project'])],
            'subscribable_id' => ['nullable', 'integer'],
        ]);

        $validated['amount'] = Money::toPaise($validated['amount']);

        $validated['subscribable_type'] = match ($validated['subscribable_type'] ?? null) {
            'contract' => MaintenanceContract::class,
            'project' => Project::class,
            default => null,
        };

        if ($validated['subscribable_type'] === null) {
            $validated['subscribable_id'] = null;
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function planRules(Request $request, ?ApiKeyPlan $plan = null): array
    {
        $validated = $this->validatedInput($request, [
            'name' => ['required', 'string', 'max:80'],
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', Rule::unique('api_key_plans', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'solution_id' => ['nullable', 'integer', Rule::exists('solutions', 'id')],
            'monthly_quota' => ['required', 'integer', 'min:1', 'max:100000000'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:100000'],
            'price' => ['required', 'numeric', 'min:0'],
            'interval' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $validated['slug'] = ($validated['slug'] ?? null) ?: str($validated['name'])->slug()->toString();
        $validated['price'] = Money::toPaise($validated['price']);
        $validated['sort_order'] ??= 0;

        return $validated;
    }

    /** @return array<string, mixed> */
    protected function options(): array
    {
        return [
            'clients' => User::query()
                ->role(Role::Client)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => [
                    'value' => $user->id,
                    'label' => $user->name,
                    'description' => $user->email,
                ]),
            'intervals' => collect(Subscription::INTERVALS)
                ->map(fn (string $interval) => [
                    'value' => $interval,
                    'label' => str($interval)->replace('_', ' ')->title()->toString(),
                ]),
        ];
    }

    protected function table(): Table
    {
        return Table::for(Subscription::query()->with('client:id,name'))
            ->searchable(['name', 'description'])
            ->sortable(['renews_on', 'amount'])
            ->defaultSort('renews_on')
            ->exportName('subscriptions')
            ->columns([
                Column::make('name', 'Subscription'),
                Column::make('client', 'Client'),
                Column::make('amount', 'Amount')->numeric()
                    ->exportUsing(fn (array $row) => $row['amountValue']),
                Column::make('interval', 'Every'),
                Column::make('renews_on', 'Renews')->sortable(),
                Column::make('auto', 'Auto renew'),
                Column::make('status', 'Status'),
            ])
            ->filters([
                Filter::multi('status', [
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'paused', 'label' => 'Paused'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ], 'Status'),
                Filter::boolean('auto_renew', 'Automatic only'),
                Filter::dateRange('renews_on', 'Renewing between'),
            ])
            ->transform(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'client' => $subscription->client?->name ?? '—',
                'clientId' => $subscription->client_id,
                'amount' => $subscription->amountLabel(),
                'amountValue' => $subscription->amount / 100,
                'amountRaw' => $subscription->amount / 100,
                'interval' => $subscription->intervalLabel(),
                'intervalValue' => $subscription->interval,
                'renews_on' => $subscription->renews_on->format('j M Y'),
                'renewsOnValue' => $subscription->renews_on->toDateString(),
                'startsOnValue' => $subscription->starts_on->toDateString(),
                'daysToRenewal' => $subscription->daysToRenewal(),
                'auto' => $subscription->auto_renew ? 'Yes' : 'No',
                'autoRenew' => $subscription->auto_renew,
                'status' => $subscription->status,
                'description' => $subscription->description,
                'lapsed' => $subscription->hasLapsed(),
            ]);
    }
}
