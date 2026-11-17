<?php

namespace App\Http\Controllers\Client;

use App\Models\MaintenanceContract;
use App\Models\RenewalItem;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Everything of the organisation's that renews or lapses, soonest first (CL16). */
class RenewalController extends ClientController
{
    public function __invoke(Request $request): Response
    {
        $client = $this->client($request);

        $rows = collect()
            ->concat(Subscription::query()->forClient($client)->active()->get()->map(fn (Subscription $subscription) => [
                'what' => 'Subscription',
                'name' => $subscription->name,
                'date' => $subscription->renews_on,
                'note' => $subscription->amountLabel().' '.strtolower($subscription->intervalLabel()).($subscription->auto_renew ? ', renews automatically' : ''),
                'href' => '/client/subscriptions',
            ]))
            ->concat(MaintenanceContract::query()->where('client_id', $client->id)->where('status', 'active')->get()->map(fn (MaintenanceContract $contract) => [
                'what' => 'Maintenance contract',
                'name' => "{$contract->reference} ({$contract->plan})",
                'date' => $contract->ends_on,
                'note' => 'Ends',
                'href' => '/client/support',
            ]))
            ->concat(RenewalItem::query()->forClient($client)->active()->get()->map(fn (RenewalItem $item) => [
                'what' => $item->kindLabel(),
                'name' => $item->name,
                'date' => $item->expires_on,
                'note' => collect([$item->provider, $item->auto_renew ? 'renews automatically' : null])->filter()->join(', '),
                'href' => null,
            ]))
            ->sortBy(fn (array $row) => $row['date']->getTimestamp())
            ->values()
            ->map(fn (array $row) => [
                ...$row,
                'date' => $row['date']->format('j M Y'),
                'days' => (int) today()->diffInDays($row['date'], false),
            ]);

        return Inertia::render('client/Renewals', ['renewals' => $rows]);
    }
}
