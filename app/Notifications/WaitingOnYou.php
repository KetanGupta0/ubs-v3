<?php

namespace App\Notifications;

use App\Models\WaitingItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** We need something from the client to carry on (CL10), or need it again. */
class WaitingOnYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WaitingItem $item, public bool $reopened = false) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->item->project;

        $mail = (new MailMessage)
            ->subject(($this->reopened ? 'Still needed: ' : 'We need from you: ').$this->item->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->reopened
                ? "Thank you for your reply on **{$this->item->title}** ({$project->name}). We still need a little more; the note on the item says what."
                : "To carry on with {$project->name}, we need **{$this->item->title}** from you.");

        if (! $this->reopened && $this->item->details) {
            $mail->line($this->item->details);
        }

        if ($this->item->due_on) {
            $mail->line('We would like it by '.$this->item->due_on->format('j M Y').'.');
        }

        if ($this->item->blocks_work) {
            $mail->line('Work on this part waits until it arrives.');
        }

        if ($this->item->kind === 'access') {
            $mail->line('Please do not send passwords by email or in the reply. Invite our team account, or tell us how you would like to share access safely.');
        }

        return $mail->action('Reply', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->reopened ? 'waiting.reopened' : 'waiting.raised',
            'title' => ($this->reopened ? 'Still needed: ' : 'We need from you: ').$this->item->title,
            'url' => $this->path(),
        ];
    }

    protected function path(): string
    {
        return "/client/projects/{$this->item->project_id}/waiting/{$this->item->id}";
    }
}
