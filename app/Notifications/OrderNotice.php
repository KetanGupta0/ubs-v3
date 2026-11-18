<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An order was paid (§4): the buyer and the organisation's finance people get
 * the receipt and what happens next; our side hears there is something to
 * deliver.
 */
class OrderNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  paid (to the client) or received (to us) */
    public function __construct(public Order $order, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $mail = (new MailMessage)->subject($this->headline())->greeting("Hello {$notifiable->name},");
        $lines = $order->items->map(fn (OrderItem $item) => '- '.$item->description.($item->quantity > 1 ? " × {$item->quantity}" : ''))->implode("\n");

        if ($this->what === 'received') {
            return $mail
                ->line("{$order->client?->organisationName()} paid {$order->totalLabel()} for {$order->number}:")
                ->line($lines)
                ->line($this->hasWorkForUs() ? 'There is work on it for us to arrange: installation, hours or a hosted plan to set up.' : 'Licences were issued and the code is ready for them to download.')
                ->action('Open the order', url("/admin/orders/{$order->id}"));
        }

        $mail->line("Thank you. We have your payment of {$order->totalLabel()} for {$order->number}:")->line($lines);

        if ($order->items->contains('kind', 'licence')) {
            $mail->line('Open the order to see your licence key and download the code. The key is shown once, so keep it somewhere safe.');
        }

        if ($this->hasWorkForUs()) {
            $mail->line('We will be in touch within one working day to arrange the rest.');
        }

        return $mail
            ->line('The invoice is on the order page.')
            ->line('To sign in, use a code sent to this address, or set a password under Security.')
            ->action('Open the order', url("/client/orders/{$order->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "order.{$this->what}",
            'title' => $this->headline(),
            'url' => $this->what === 'received' ? "/admin/orders/{$this->order->id}" : "/client/orders/{$this->order->id}",
        ];
    }

    protected function headline(): string
    {
        return $this->what === 'received'
            ? "Order paid: {$this->order->number}, ".Money::display($this->order->total)
            : "Your order {$this->order->number} is paid";
    }

    protected function hasWorkForUs(): bool
    {
        return $this->order->items->contains(fn (OrderItem $item) => $item->kind === 'plan'
            || ($item->kind === 'addon' && ($item->terms['kind'] ?? null) === 'service'));
    }
}
