<?php

namespace App\Notifications;

use App\Models\MessageReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A message in a group somebody runs was reported by a member. */
class MessageReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MessageReport $report) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A message in '.$this->report->conversation->displayTitle().' was reported')
            ->greeting("Hello {$notifiable->name},")
            ->line('A member reported a message in the **'.$this->report->conversation->displayTitle().'** group.')
            ->action('Look at it', url($this->path($notifiable)))
            ->line('Nothing has been taken down: that is for a person to decide.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'chat.reported',
            'title' => 'Reported message in '.$this->report->conversation->displayTitle(),
            'url' => $this->path($notifiable),
        ];
    }

    /** The queue for staff who may answer chat; the group itself for a trainer who may not. */
    protected function path(object $notifiable): string
    {
        return method_exists($notifiable, 'hasPermission') && $notifiable->hasPermission('chat.reply')
            ? '/admin/chat/reports?filter[status]=open'
            : "/chat/{$this->report->conversation_id}";
    }
}
