<?php

namespace App\Notifications;

use App\Models\MilestoneSignoff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The client accepted a milestone, or asked for changes (CL9). */
class MilestoneAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MilestoneSignoff $signoff) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().', answered by '.($this->signoff->decider?->name ?? 'the client').'.');

        if ($this->signoff->comments) {
            $mail->line('Their comments: '.$this->signoff->comments);
        }

        if ($this->signoff->payment_request_id) {
            $mail->line('Its invoice has been raised and sent to their finance contacts.');
        }

        return $mail->action('Open the project', url("/admin/projects/{$this->signoff->project_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'milestone.'.($this->signoff->decision === 'accepted' ? 'accepted' : 'changes_requested'),
            'title' => $this->headline(),
            'url' => "/admin/projects/{$this->signoff->project_id}",
        ];
    }

    protected function headline(): string
    {
        $milestone = $this->signoff->milestone;
        $client = $milestone->project->client?->organisationName() ?? 'The client';

        return $this->signoff->decision === 'accepted'
            ? "{$client} accepted {$milestone->title}"
            : "{$client} asked for changes to {$milestone->title}";
    }
}
