<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Notifications\RenewalDue;
use Illuminate\Console\Command;

/**
 * Warns clients before something renews.
 *
 * Runs daily. Each subscription records which windows have already been sent,
 * so the thirty day warning goes out once rather than thirty times, and a
 * subscription renewed early stops being reminded about.
 */
class SendRenewalReminders extends Command
{
    protected $signature = 'renewals:remind {--days=* : Override the reminder windows}';

    protected $description = 'Tell clients about renewals coming up';

    /** How far ahead we warn, in days. */
    public const WINDOWS = [30, 14, 3];

    public function handle(): int
    {
        $windows = $this->option('days') ?: self::WINDOWS;
        $windows = collect($windows)->map(fn ($days) => (int) $days)->sort()->values();

        $sent = 0;

        Subscription::query()
            ->active()
            ->with('client')
            ->whereBetween('renews_on', [today(), today()->addDays($windows->max())])
            ->each(function (Subscription $subscription) use ($windows, &$sent) {
                $daysAhead = $subscription->daysToRenewal();

                // The tightest window that has been reached. Running late, a
                // subscription two days out gets the three day warning rather
                // than all three at once.
                $window = $windows->filter(fn (int $days) => $daysAhead <= $days)->min();

                if ($window === null) {
                    return;
                }

                $already = $subscription->reminders_sent ?? [];

                if (in_array($window, $already, true)) {
                    return;
                }

                $subscription->client?->notify(new RenewalDue($subscription, $daysAhead));

                $subscription->forceFill([
                    'reminders_sent' => [...$already, $window],
                ])->save();

                $sent++;
            });

        $this->info("Sent {$sent} renewal ".str('reminder')->plural($sent).'.');

        return self::SUCCESS;
    }
}
