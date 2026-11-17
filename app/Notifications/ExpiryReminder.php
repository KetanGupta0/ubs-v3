<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Something lapses soon unless renewed (CL16): a domain, hosting, an SSL
 * certificate, an update window or a maintenance contract.
 */
class ExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $what,
        public string $name,
        public Carbon $expiresOn,
        public int $daysAhead,
        public bool $autoRenew,
        public string $clientPath,
        public ?string $clientName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->expiresOn->format('j M Y');
        $isClient = method_exists($notifiable, 'isClient') && $notifiable->isClient();

        return (new MailMessage)
            ->subject("{$this->what} {$this->name} ".($this->daysAhead <= 0 ? 'lapses today' : "lapses on {$when}"))
            ->greeting("Hello {$notifiable->name},")
            ->line(($isClient || ! $this->clientName ? '' : "{$this->clientName}: ")."**{$this->name}** ({$this->what}) lapses on **{$when}**, in {$this->daysAhead} ".str('day')->plural($this->daysAhead).'.')
            ->line($this->autoRenew
                ? 'It is set to renew automatically. Check the payment details on file are current.'
                : 'It does not renew by itself. Renew it before then, or tell us and we will.')
            ->action($isClient ? 'See your renewals' : 'Open renewals', url($isClient ? $this->clientPath : '/admin/renewals'));
    }

    public function toArray(object $notifiable): array
    {
        $isClient = method_exists($notifiable, 'isClient') && $notifiable->isClient();

        return [
            'kind' => 'renewal.due',
            'title' => "{$this->name} lapses on {$this->expiresOn->format('j M Y')}",
            'url' => $isClient ? $this->clientPath : '/admin/renewals',
        ];
    }
}
