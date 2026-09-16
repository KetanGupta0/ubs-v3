<?php

namespace App\Http\Controllers\Client;

use App\Models\Subscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Things that renew, and whether the client wants them to.
 *
 * Auto renew is the one field a client may change here. Cancelling outright is
 * deliberately a conversation rather than a button: a hosting subscription
 * switched off by a mis-click takes a site down.
 */
class SubscriptionController extends ClientController
{
    public function index(Request $request): Response
    {
        $client = $this->client($request);

        $subscriptions = Subscription::query()
            ->forClient($client)
            ->orderBy('renews_on')
            ->get();

        return Inertia::render('client/subscriptions/Index', [
            'subscriptions' => $subscriptions->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'name' => $subscription->name,
                'description' => $subscription->description,
                'amount' => $subscription->amountLabel(),
                'interval' => $subscription->intervalLabel(),
                'startsOn' => $subscription->starts_on->format('j M Y'),
                'renewsOn' => $subscription->renews_on->format('j M Y'),
                'daysToRenewal' => $subscription->daysToRenewal(),
                'autoRenew' => $subscription->auto_renew,
                'status' => $subscription->status,
                'lapsed' => $subscription->hasLapsed(),
            ]),
            'renewingSoon' => $subscriptions
                ->filter(fn (Subscription $s) => $s->status === 'active' && $s->daysToRenewal() <= 30)
                ->count(),
        ]);
    }

    public function setAutoRenew(Request $request, int $subscription): RedirectResponse
    {
        $record = $this->own($request, Subscription::query()
            ->forClient($this->client($request)), $subscription);

        $validated = $request->validate(['auto_renew' => ['required', 'boolean']]);

        $record->forceFill(['auto_renew' => $validated['auto_renew']])->save();

        return back()->with('success', $validated['auto_renew']
            ? 'This will renew automatically. We will still tell you before it does.'
            : 'Automatic renewal is off. We will send you a reminder instead.');
    }
}
