<?php

namespace App\Notifications;

use App\Models\MilestoneSignoff;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** We ask the client's signatories to accept a milestone (CL9). */
class MilestoneSignoffRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public MilestoneSignoff $signoff) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $milestone = $this->signoff->milestone;
        $project = $milestone->project;

        $mail = (new MailMessage)
            ->subject("Please review: {$milestone->title} ({$project->name})")
            ->greeting("Hello {$notifiable->name},")
            ->line("We have reached **{$milestone->title}** on {$project->name}, and ask you to accept it.");

        foreach (array_slice($this->signoff->deliverables ?? [], 0, 10) as $deliverable) {
            $mail->line("- {$deliverable}");
        }

        if ($milestone->payment_amount) {
            $mail->line('Accepting it raises its invoice of '.Money::display((int) $milestone->payment_amount).' before tax.');
        }

        return $mail
            ->line('If something is not right, ask for changes and tell us what, and we will come back to you.')
            ->action('Review the milestone', url($this->path()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'milestone.signoff_requested',
            'title' => "Please review: {$this->signoff->milestone->title}",
            'url' => $this->path(),
        ];
    }

    protected function path(): string
    {
        return "/client/projects/{$this->signoff->project_id}/milestones/{$this->signoff->milestone_id}";
    }
}
