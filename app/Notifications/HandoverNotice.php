<?php

namespace App\Notifications;

use App\Models\Handover;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A handover pack was published for the client, or acknowledged by them (CL15). */
class HandoverNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  published or acknowledged */
    public function __construct(public Handover $handover, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->handover->project;

        if ($this->what === 'acknowledged') {
            return (new MailMessage)
                ->subject("Handover acknowledged: {$project->name}")
                ->greeting("Hello {$notifiable->name},")
                ->line(($project->client?->organisationName() ?? 'The client')." acknowledged revision {$this->handover->revision} of the handover for {$project->name}.")
                ->action('Open the project', url("/admin/projects/{$project->id}"));
        }

        return (new MailMessage)
            ->subject("Your handover pack: {$project->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The handover pack for **{$project->name}** is ready: where the code is, how it is deployed, licences, training and the warranty.")
            ->line('Please read it and acknowledge it. It stays in your account for whenever you need it.')
            ->action('Read the handover', url("/client/projects/{$project->id}/handover"));
    }

    public function toArray(object $notifiable): array
    {
        $project = $this->handover->project;

        return [
            'kind' => "handover.{$this->what}",
            'title' => $this->what === 'acknowledged' ? "Handover acknowledged: {$project->name}" : "Handover ready: {$project->name}",
            'url' => $this->what === 'acknowledged' ? "/admin/projects/{$project->id}" : "/client/projects/{$project->id}/handover",
        ];
    }
}
