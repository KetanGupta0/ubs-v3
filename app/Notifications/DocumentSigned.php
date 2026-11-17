<?php

namespace App\Notifications;

use App\Models\Agreement;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client signed an agreement, or accepted a proposal by signing it. */
class DocumentSigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Agreement|Proposal $document) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function path(): string
    {
        return $this->document instanceof Proposal
            ? "/admin/proposals/{$this->document->id}"
            : "/admin/agreements/{$this->document->id}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organisation = $this->document->client->organisationName();
        $what = $this->document instanceof Proposal ? 'accepted and signed proposal' : 'signed';

        return (new MailMessage)
            ->subject("{$organisation} {$what} {$this->document->number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$organisation} {$what} **{$this->document->number}: {$this->document->title}**.")
            ->line($this->document instanceof Agreement ? 'It is waiting for our countersignature.' : 'The signed copy is on the proposal.')
            ->action('Open it', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'document.signed',
            'title' => "{$this->document->client->organisationName()} signed {$this->document->number}",
            'url' => $this->path(),
        ];
    }
}
