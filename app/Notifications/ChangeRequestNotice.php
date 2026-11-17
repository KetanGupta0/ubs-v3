<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A change request moved (CL12): the client asked for one or declined one,
 * which our side hears about, or we quoted one, which the client's
 * signatories hear about.
 */
class ChangeRequestNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $what  raised, quoted or declined */
    public function __construct(public ChangeRequest $change, public string $what) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $change = $this->change;
        $client = $change->client?->organisationName() ?? 'The client';

        $mail = (new MailMessage)->subject($this->headline())->greeting("Hello {$notifiable->name},");

        return match ($this->what) {
            'quoted' => $mail
                ->line("We have estimated the change you asked for on {$change->project->name}: **{$change->title}**.")
                ->line("It comes to {$change->amountLabel()} before tax, with {$change->daysLabel()}.")
                ->line('Read the addendum, and approve it by signing it, or decline it and tell us why.')
                ->action('Review the change', url($this->path($notifiable))),
            'declined' => $mail
                ->line("{$client} declined **{$change->number}: {$change->title}**.")
                ->line('Their reason: '.$change->decline_reason)
                ->action('Open it', url($this->path($notifiable))),
            default => $mail
                ->line("{$client} asked for a change on {$change->project->name}: **{$change->title}**.")
                ->line('Estimate it and send it as an addendum for them to approve.')
                ->action('Estimate it', url($this->path($notifiable))),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "change_request.{$this->what}",
            'title' => $this->headline(),
            'url' => $this->path($notifiable),
        ];
    }

    protected function headline(): string
    {
        return match ($this->what) {
            'quoted' => "To approve: {$this->change->title}",
            'declined' => "Declined: {$this->change->number}",
            default => "Change asked for: {$this->change->title}",
        };
    }

    protected function path(object $notifiable): string
    {
        return method_exists($notifiable, 'isClient') && $notifiable->isClient()
            ? "/client/projects/{$this->change->project_id}/changes/{$this->change->id}"
            : "/admin/change-requests/{$this->change->id}";
    }
}
