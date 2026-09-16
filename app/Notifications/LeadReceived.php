<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the team an enquiry has arrived.
 *
 * Goes by email with the full context, and by SMS with just enough to know
 * whether it is worth opening the laptop for.
 */
class LeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    public function via(object $notifiable): array
    {
        return $notifiable->mobile ? ['mail', SmsChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("New enquiry {$this->lead->reference} — {$this->lead->subject()}")
            ->greeting('New enquiry')
            ->line("**{$this->lead->subject()}**")
            ->line("From: {$this->lead->name}".($this->lead->company ? " ({$this->lead->company})" : ''))
            ->line("Email: {$this->lead->email}");

        if ($this->lead->mobile) {
            $message->line("Mobile: {$this->lead->mobile}");
        }

        if ($this->lead->budget_band) {
            $message->line("Budget: {$this->lead->budget_band}");
        }

        if ($this->lead->timeline) {
            $message->line("Timeline: {$this->lead->timeline}");
        }

        return $message
            ->line('---')
            ->line($this->lead->message)
            ->line('---')
            ->line('Came from: '.($this->lead->source_page ?: 'unknown'))
            ->line("Reference {$this->lead->reference}");
    }

    /** The channel sends whatever this returns to the notifiable's number. */
    public function toSms(object $notifiable): string
    {
        return "New enquiry {$this->lead->reference} from {$this->lead->name}. {$this->lead->subject()}.";
    }
}
