<?php

namespace App\Notifications;

use App\Models\Agreement;
use App\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Both sides have signed an agreement; the client's signatories get the copy. */
class DocumentCountersigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Agreement|Proposal $document) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Signed by both: {$this->document->number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We have countersigned **{$this->document->number}: {$this->document->title}**, so it is signed by both sides.")
            ->line('The signed copy, with its certificate of signing, is in your account.')
            ->action('Download it', url("/client/agreements/{$this->document->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'document.countersigned',
            'title' => "Signed by both: {$this->document->number}",
            'url' => "/client/agreements/{$this->document->id}",
        ];
    }
}
