<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms to the sender that their enquiry arrived.
 *
 * Deliberately plain and specific. It repeats what they asked about and gives a
 * reference, so the reply is obviously from a person who read it rather than an
 * automatic thank you.
 */
class LeadAcknowledgement extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $about = match (true) {
            (bool) $this->lead->solution_id => 'about '.$this->lead->solution?->title,
            (bool) $this->lead->service_id => 'about '.$this->lead->service?->title,
            (bool) $this->lead->course_id => 'about '.$this->lead->course?->title,
            default => null,
        };

        return (new MailMessage)
            ->subject("We have your enquiry ({$this->lead->reference})")
            ->greeting("Hello {$this->lead->name},")
            ->line('Thanks for getting in touch'.($about ? " {$about}" : '').'.')
            ->line('Someone from our team will read this properly and reply within one working day. If it is urgent, reply to this email and say so.')
            ->line("Your reference is **{$this->lead->reference}**. Quoting it saves us both time.")
            ->salutation('— Unboundbyte Solutions');
    }
}
