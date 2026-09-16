<?php

namespace App\Notifications;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A renewal is coming up.
 *
 * Sent at set intervals before the date, and the subscription records which
 * intervals have already gone out, so a daily job cannot send the thirty day
 * warning thirty times.
 */
class RenewalDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public int $daysAhead,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $renews = $this->subscription->renews_on->format('j M Y');

        return (new MailMessage)
            ->subject($this->subscription->name.' renews on '.$renews)
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->subscription->name}** renews on **{$renews}**, in {$this->daysAhead} days.")
            ->line('**Amount:** '.$this->subscription->amountLabel().' '.strtolower($this->subscription->intervalLabel()))
            ->line($this->subscription->auto_renew
                ? 'It is set to renew automatically. Nothing to do unless you want to stop it.'
                : 'Automatic renewal is off, so tell us if you want it to continue.')
            ->action('Review your subscriptions', url('/client/subscriptions'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'subscription.renewal',
            'name' => $this->subscription->name,
            'renewsOn' => $this->subscription->renews_on->toDateString(),
            'url' => '/client/subscriptions',
        ];
    }
}
