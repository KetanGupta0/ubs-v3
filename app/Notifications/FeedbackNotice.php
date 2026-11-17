<?php

namespace App\Notifications;

use App\Models\FeedbackItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Staging feedback moved (CL13): reported or reopened by the client, which
 * our side hears about; fixed, declined or commented on by us, which the
 * person who reported it hears about.
 */
class FeedbackNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  new, reopened, comment, or the status we moved it to */
    public function __construct(public FeedbackItem $item, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $line = match ($this->what) {
            'new' => "{$this->who()} reported on {$this->item->project->name}: **{$this->item->title}**.",
            'reopened' => "{$this->who()} says **{$this->item->title}** still happens after the fix.",
            'fixed' => "**{$this->item->title}** is fixed on staging. Please check it, and tell us if it works.",
            'declined' => "We are not changing **{$this->item->title}**; the note on it says why.",
            default => "There is a new comment on **{$this->item->title}**.",
        };

        return (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hello {$notifiable->name},")
            ->line($line)
            ->action('Open it', url($this->path($notifiable)));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "feedback.{$this->what}",
            'title' => $this->headline(),
            'url' => $this->path($notifiable),
        ];
    }

    protected function headline(): string
    {
        return match ($this->what) {
            'new' => "Reported on staging: {$this->item->title}",
            'reopened' => "Still happening: {$this->item->title}",
            'fixed' => "Fixed, please check: {$this->item->title}",
            'declined' => "Not changing: {$this->item->title}",
            default => "New comment: {$this->item->title}",
        };
    }

    protected function who(): string
    {
        return $this->item->client?->organisationName() ?? 'The client';
    }

    protected function path(object $notifiable): string
    {
        return method_exists($notifiable, 'isClient') && $notifiable->isClient()
            ? "/client/projects/{$this->item->project_id}/feedback/{$this->item->id}"
            : "/admin/feedback/{$this->item->id}";
    }
}
