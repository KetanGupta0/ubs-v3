<?php

namespace App\Notifications;

use App\Models\ProjectUpdate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ProjectUpdate $update) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->update->project;

        return (new MailMessage)
            ->subject('Update on '.$project->name)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->update->title ? '**'.$this->update->title.'**' : 'There is an update on your project.')
            ->line($this->update->body)
            ->line('The project is **'.$project->progress_percent.'%** through'
                .($project->phase ? ', currently in '.$project->phase.'.' : '.'))
            ->action('Open the project', url("/client/projects/{$project->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'project.updated',
            'project' => $this->update->project->name,
            'title' => $this->update->title,
            'url' => "/client/projects/{$this->update->project_id}",
        ];
    }
}
