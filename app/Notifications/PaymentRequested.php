<?php

namespace App\Notifications;

use App\Models\PaymentRequest;
use App\Notifications\Channels\SmsChannel;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a client that money is being asked for.
 *
 * It carries the invoice number and the amount, because a payment message with
 * neither is indistinguishable from a phishing attempt, and a client who has
 * learned to ignore ours will ignore the real one too.
 */
class PaymentRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentRequest $request,
        public ?string $invoiceNumber = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if (filled($notifiable->mobile)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Payment request: '.$this->request->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->request->description ?: 'We have raised a payment request on your account.')
            ->line('**Amount:** '.Money::display($this->request->total));

        if ($this->invoiceNumber) {
            $message->line('**Invoice:** '.$this->invoiceNumber);
        }

        if ($this->request->due_on) {
            $message->line('**Due by:** '.$this->request->due_on->format('j M Y'));
        }

        return $message
            ->action('View and pay', url("/client/payments/{$this->request->id}"))
            ->line('The invoice is on that page and can be downloaded before you pay.');
    }

    public function toSms(object $notifiable): string
    {
        return sprintf(
            '%s: %s of %s is due%s. Pay at %s',
            config('company.name'),
            $this->request->title,
            Money::display($this->request->total),
            $this->request->due_on ? ' by '.$this->request->due_on->format('j M') : '',
            url("/client/payments/{$this->request->id}"),
        );
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payment.requested',
            'reference' => $this->request->reference,
            'title' => $this->request->title,
            'amount' => Money::display($this->request->total),
            'url' => "/client/payments/{$this->request->id}",
        ];
    }
}
