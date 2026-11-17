<?php

namespace App\Notifications;

use App\Models\WaitingItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The client replied to something we asked them for (CL10). */
class WaitingItemAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WaitingItem $item) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->who()} replied: {$this->item->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->who()} replied on **{$this->item->title}** ({$this->item->project->name}).")
            ->line('Close it if that is everything, or ask again saying what is missing.')
            ->action('Open the project', url("/admin/projects/{$this->item->project_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'waiting.answered',
            'title' => "{$this->who()} replied: {$this->item->title}",
            'url' => "/admin/projects/{$this->item->project_id}",
        ];
    }

    protected function who(): string
    {
        return $this->item->client?->organisationName() ?? 'The client';
    }
}
