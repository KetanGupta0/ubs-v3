<?php

namespace App\Notifications;

use App\Models\Agreement;
use App\Models\ChangeRequest;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client signed an agreement, or accepted a proposal by signing it. */
class DocumentSigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Agreement|Proposal|ChangeRequest $document) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    protected function path(): string
    {
        return match (true) {
            $this->document instanceof Proposal => "/admin/proposals/{$this->document->id}",
            $this->document instanceof ChangeRequest => "/admin/change-requests/{$this->document->id}",
            default => "/admin/agreements/{$this->document->id}",
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organisation = $this->document->client->organisationName();
        $what = match (true) {
            $this->document instanceof Proposal => 'accepted and signed proposal',
            $this->document instanceof ChangeRequest => 'approved and signed change request',
            default => 'signed',
        };

        return (new MailMessage)
            ->subject("{$organisation} {$what} {$this->document->number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$organisation} {$what} **{$this->document->number}: {$this->document->title}**.")
            ->line(match (true) {
                $this->document instanceof Agreement => 'It is waiting for our countersignature.',
                $this->document instanceof ChangeRequest => 'The project\'s value and timeline have been updated, and the work is a new milestone.',
                default => 'The signed copy is on the proposal.',
            })
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
