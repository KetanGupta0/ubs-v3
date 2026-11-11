<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Models\MessageTemplate;
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
        $topic = $this->lead->solution?->title ?? $this->lead->service?->title ?? $this->lead->course?->title;
        $template = MessageTemplate::find_by_key('lead.acknowledgement');

        $message = (new MailMessage)->greeting("Hello {$this->lead->name},")->salutation('— '.config('app.name'));

        if ($template) {
            // The wording set in the panel, used as written. Until November
            // 2026 this template could be switched on and changed nothing.
            $values = [
                'name' => $this->lead->name,
                'reference' => $this->lead->reference,
                'subject' => $topic,
                'company' => config('app.name'),
            ];

            $message->subject($template->renderSubject($values) ?? "We have your enquiry ({$this->lead->reference})");

            foreach (preg_split('/\n{2,}/', $template->render($values)) as $paragraph) {
                $message->line(trim($paragraph));
            }

            return $message;
        }

        return $message
            ->subject("We have your enquiry ({$this->lead->reference})")
            ->line('Thanks for getting in touch'.($topic ? " about {$topic}" : '').'.')
            ->line('Someone from our team will read this properly and reply within one working day. If it is urgent, reply to this email and say so.')
            ->line("Your reference is **{$this->lead->reference}**. Quoting it saves us both time.");
    }
}
