<?php

namespace App\Notifications;

use App\Models\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** A student has been kept from posting in their batch group for a while. */
class GroupSilenced extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Conversation $conversation,
        public Carbon $until,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('You cannot post in '.$this->conversation->displayTitle().' for now')
            ->greeting("Hello {$notifiable->name},")
            ->line('You cannot post in the **'.$this->conversation->displayTitle().'** group until '.$this->until->format('g:i a, j M').'. You can still read it.');

        if (filled($this->reason)) {
            $message->line('The reason given: '.$this->reason);
        }

        return $message
            ->line('If you think this is a mistake, ask from your account and somebody will look at it.')
            ->action('Ask a question', url('/student/support/new'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'chat.silenced',
            'title' => 'You cannot post in '.$this->conversation->displayTitle().' until '.$this->until->format('g:i a, j M'),
            'url' => "/chat/{$this->conversation->id}",
        ];
    }
}
