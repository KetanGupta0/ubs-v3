<?php

namespace App\Http\Controllers\Client;

use App\Models\ApiKey;
use App\Models\ApiKeyPlan;
use App\Models\PaymentRequest;
use App\Models\Project;
use App\Services\Billing\Invoicer;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API keys, for the products that need them.
 *
 * The secret is shown once, on the screen that issues it, and never again. A
 * key we could read back to a client is a key that anybody holding a database
 * backup can read too, so a lost key is rotated rather than recovered.
 */
class ApiKeyController extends ClientController
{
    public function index(Request $request): Response
    {
        $client = $this->client($request);

        return Inertia::render('client/api-keys/Index', [
            'keys' => ApiKey::query()
                ->forClient($client)
                ->with(['plan:id,name,monthly_quota,rate_limit_per_minute', 'project:id,name'])
                ->latest('id')
                ->get()
                ->map(fn (ApiKey $key) => [
                    'id' => $key->id,
                    'label' => $key->label,
                    'masked' => $key->masked(),
                    'environment' => $key->environment,
                    'status' => $key->status,
                    'usable' => $key->isUsable(),
                    'plan' => $key->plan?->name,
                    'project' => $key->project?->name,
                    'quota' => $key->quota(),
                    'quotaUsed' => $key->quota_used,
                    'quotaPercent' => $key->quotaPercent(),
                    'exceeded' => $key->hasExceededQuota(),
                    'rateLimit' => $key->plan?->rate_limit_per_minute,
                    'expiresAt' => $key->expires_at?->format('j M Y'),
                    'lastUsedAt' => $key->last_used_at?->diffForHumans(),
                    'createdAt' => $key->created_at->format('j M Y'),
                    'usage' => $this->usageSeries($key),
                ]),

            'plans' => ApiKeyPlan::query()
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ApiKeyPlan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'quota' => $plan->monthly_quota,
                    'rateLimit' => $plan->rate_limit_per_minute,
                    'price' => $plan->price === 0 ? 'Free' : Money::display($plan->price),
                    'priceLabel' => $plan->priceLabel(),
                    'interval' => $plan->interval,
                ]),

            'projects' => Project::query()
                ->forClient($client)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => $project->name]),
        ]);
    }

    /**
     * Ask for a key.
     *
     * A paid plan raises a payment request rather than issuing straight away,
     * because the key is the thing being bought. A free plan issues at once.
     */
    public function store(Request $request, Invoicer $invoicer): RedirectResponse
    {
        $client = $this->client($request);

        $validated = $this->validatedInput($request, [
            'label' => ['required', 'string', 'max:80'],
            'plan_id' => ['required', 'integer', Rule::exists('api_key_plans', 'id')->where('is_active', true)],
            'project_id' => ['nullable', 'integer'],
            'environment' => ['required', Rule::in(['test', 'live'])],
        ]);

        $plan = ApiKeyPlan::query()->findOrFail($validated['plan_id']);

        $project = $validated['project_id']
            ? Project::query()->forClient($client)->find($validated['project_id'])
            : null;

        // A test key is always free, whatever the plan costs, because nobody
        // should have to pay to find out whether the integration works.
        if ($plan->price > 0 && $validated['environment'] === 'live') {
            $payment = PaymentRequest::query()->create([
                'user_id' => $client->id,
                'payable_type' => ApiKeyPlan::class,
                'payable_id' => $plan->id,
                'title' => "{$plan->name} API key",
                'description' => "API key for {$validated['label']}, billed {$plan->interval}.",
                'due_on' => today()->addDays(7),
                'raised_by' => null,
                'notes' => json_encode([
                    'label' => $validated['label'],
                    'environment' => $validated['environment'],
                    'project_id' => $project?->id,
                ]),
            ]);

            $payment->price($plan->price, $invoicer->defaultTaxRate())->save();

            return redirect()
                ->route('client.payments.show', $payment->id)
                ->with('info', 'Pay for the plan and the key is issued straight away.');
        }

        $issued = $this->issue($client->id, $plan, $validated['label'], $validated['environment'], $project?->id);

        return back()->with([
            'success' => 'Key issued. Copy it now: this is the only time it is shown.',
            'issuedApiKey' => $issued,
        ]);
    }

    /** Replace a key, keeping the old one alive briefly is not offered: a rotated key is dead. */
    public function rotate(Request $request, int $apiKey): RedirectResponse
    {
        $record = $this->own($request, ApiKey::query()->forClient($this->client($request)), $apiKey);

        abort_unless($record->status === 'active', 422, 'Only an active key can be rotated.');

        $generated = ApiKey::generate($record->environment);

        DB::transaction(function () use ($record, $generated) {
            $replacement = ApiKey::query()->create([
                'client_id' => $record->client_id,
                'api_key_plan_id' => $record->api_key_plan_id,
                'project_id' => $record->project_id,
                'label' => $record->label,
                'environment' => $record->environment,
                'key_prefix' => $generated['prefix'],
                'key_hash' => $generated['hash'],
                'last_four' => $generated['lastFour'],
                'status' => 'active',
                'quota_used' => $record->quota_used,
                'quota_period_start' => $record->quota_period_start ?? today()->startOfMonth(),
                'expires_at' => $record->expires_at,
                'rotated_from_id' => $record->id,
            ]);

            $record->forceFill([
                'status' => 'rotated',
                'revoked_at' => now(),
                'revoked_reason' => "Rotated into key #{$replacement->id}",
            ])->save();
        });

        return back()->with([
            'success' => 'Rotated. The previous key stopped working immediately.',
            'issuedApiKey' => ['key' => $generated['key'], 'label' => $record->label],
        ]);
    }

    public function revoke(Request $request, int $apiKey): RedirectResponse
    {
        $record = $this->own($request, ApiKey::query()->forClient($this->client($request)), $apiKey);

        $validated = $this->validatedInput($request, [
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $record->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_reason' => $validated['reason'] ?? 'Revoked by the client',
        ])->save();

        return back()->with('success', 'Key revoked. Anything using it will stop working now.');
    }

    /**
     * Issue a key and hand back the one and only readable copy.
     *
     * @return array{key: string, label: string}
     */
    public function issue(int $clientId, ApiKeyPlan $plan, string $label, string $environment, ?int $projectId): array
    {
        $generated = ApiKey::generate($environment);

        ApiKey::query()->create([
            'client_id' => $clientId,
            'api_key_plan_id' => $plan->id,
            'project_id' => $projectId,
            'label' => $label,
            'environment' => $environment,
            'key_prefix' => $generated['prefix'],
            'key_hash' => $generated['hash'],
            'last_four' => $generated['lastFour'],
            'status' => 'active',
            'quota_period_start' => today()->startOfMonth(),
        ]);

        return ['key' => $generated['key'], 'label' => $label];
    }

    /** Thirty days of counts, zero filled, so the chart has no gaps. */
    protected function usageSeries(ApiKey $key): array
    {
        $logs = $key->usage()
            ->where('date', '>=', today()->subDays(29))
            ->pluck('request_count', 'date')
            ->mapWithKeys(fn ($count, $date) => [substr((string) $date, 0, 10) => $count]);

        return collect(range(29, 0))
            ->map(function (int $daysAgo) use ($logs) {
                $date = today()->subDays($daysAgo)->toDateString();

                return ['date' => $date, 'count' => (int) ($logs[$date] ?? 0)];
            })
            ->all();
    }
}
