<?php

namespace App\Console\Commands;

use App\Models\MaintenanceContract;
use App\Models\PaymentRequest;
use App\Models\RenewalItem;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\ExpiryReminder;
use App\Notifications\PaymentRequested;
use App\Notifications\RenewalDue;
use App\Services\Admin\Auditor;
use App\Services\Billing\Invoicer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Warns before anything of a client's lapses, and renews what renews itself.
 *
 * Runs daily. Subscriptions, maintenance contracts and renewal items
 * (domains, hosting, certificates, update windows; CL16) each record which
 * windows have been sent, so the thirty day warning goes out once, and one
 * renewed early stops being reminded about. A subscription set to renew
 * automatically is billed on its renewal date and moved on a period (CL16).
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'renewals:remind {--days=* : Override the reminder windows}';

    protected $description = 'Tell clients about renewals coming up, and renew what renews itself';

    /** How far ahead we warn, in days. */
    public const WINDOWS = [30, 14, 3];

    public function handle(Invoicer $invoicer, Auditor $auditor): int
    {
        $windows = collect($this->option('days') ?: self::WINDOWS)->map(fn ($days) => (int) $days)->sort()->values();
        $renewed = $this->renewAutomatically($invoicer, $auditor);
        $sent = 0;
        $owners = User::query()->where('is_owner', true)->get();

        Subscription::query()
            ->active()
            ->with('client')
            ->whereBetween('renews_on', [today(), today()->addDays($windows->max())])
            ->each(function (Subscription $subscription) use ($windows, &$sent) {
                $sent += $this->remindOnce($subscription, $subscription->daysToRenewal(), $windows, function (int $days) use ($subscription) {
                    // To the organisation's finance people (CL8), not only the account holder.
                    Notification::send($subscription->client->clientRecipients('billing'), new RenewalDue($subscription, $days));
                });
            });

        MaintenanceContract::query()
            ->where('status', 'active')
            ->with('client')
            ->whereBetween('ends_on', [today(), today()->addDays($windows->max())])
            ->each(function (MaintenanceContract $contract) use ($windows, $owners, &$sent) {
                $days = (int) today()->diffInDays($contract->ends_on, false);

                $sent += $this->remindOnce($contract, $days, $windows, function (int $days) use ($contract, $owners) {
                    $notice = new ExpiryReminder('Maintenance contract', "{$contract->reference} ({$contract->plan})", $contract->ends_on, $days, false, '/client/renewals', $contract->client?->organisationName());
                    Notification::send($contract->client->clientRecipients('billing')->concat($owners), $notice);
                });
            });

        RenewalItem::query()
            ->active()
            ->with('client')
            ->whereBetween('expires_on', [today(), today()->addDays($windows->max())])
            ->each(function (RenewalItem $item) use ($windows, $owners, &$sent) {
                $sent += $this->remindOnce($item, $item->daysToExpiry(), $windows, function (int $days) use ($item, $owners) {
                    $notice = new ExpiryReminder($item->kindLabel(), $item->name, $item->expires_on, $days, $item->auto_renew, '/client/renewals', $item->client?->organisationName());
                    $people = $item->client->clientRecipients($item->audience());
                    Notification::send($item->isEmergencyIfMissed() ? $people->concat($owners) : $people, $notice);
                });
            });

        $this->info("Sent {$sent} renewal ".str('reminder')->plural($sent).", renewed {$renewed} ".str('subscription')->plural($renewed).'.');

        return self::SUCCESS;
    }

    /**
     * Send the tightest window reached, once. Running late, something two
     * days out gets the three day warning rather than all three at once.
     */
    protected function remindOnce(Model $record, int $daysAhead, Collection $windows, callable $send): int
    {
        $window = $windows->filter(fn (int $days) => $daysAhead <= $days)->min();
        $already = $record->reminders_sent ?? [];

        if ($window === null || in_array($window, $already, true)) {
            return 0;
        }

        $send($daysAhead);
        $record->forceFill(['reminders_sent' => [...$already, $window]])->save();

        return 1;
    }

    /**
     * A subscription set to renew automatically is billed on its renewal
     * date, before tax at the usual rate, and moved on one period. Claimed
     * under a row lock, so overlapping runs bill it once.
     */
    protected function renewAutomatically(Invoicer $invoicer, Auditor $auditor): int
    {
        $renewed = 0;

        Subscription::query()->active()->where('auto_renew', true)->whereDate('renews_on', '<=', today())->pluck('id')
            ->each(function (int $id) use ($invoicer, $auditor, &$renewed) {
                $payment = DB::transaction(function () use ($id, $invoicer) {
                    $subscription = Subscription::query()->lockForUpdate()->find($id);

                    if (! $subscription || ! $subscription->auto_renew || $subscription->renews_on->gt(today())) {
                        return null;
                    }

                    $from = $subscription->renews_on->copy();
                    $next = $subscription->nextRenewalDate();

                    $payment = PaymentRequest::query()->create([
                        'user_id' => $subscription->client->clientAccount()->id,
                        'payable_type' => Subscription::class,
                        'payable_id' => $subscription->id,
                        'title' => "{$subscription->name}: {$from->format('j M Y')} to {$next->copy()->subDay()->format('j M Y')}",
                        'description' => 'Renewed automatically.',
                        'due_on' => today()->addDays(7),
                    ]);
                    $payment->price((int) $subscription->amount, $invoicer->defaultTaxRate())->save();
                    $invoicer->issueFor($payment);

                    $subscription->forceFill(['renews_on' => $next, 'reminders_sent' => []])->save();

                    return $payment->fresh(['invoice', 'user']);
                });

                if ($payment) {
                    $auditor->action('subscription.renewed', $payment, ['subscription' => $id, 'total' => $payment->total], $payment->reference);
                    Notification::send($payment->user->clientRecipients('billing'), new PaymentRequested($payment, $payment->invoice->number));
                    $renewed++;
                }
            });

        return $renewed;
    }
}
