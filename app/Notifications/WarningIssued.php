<?php

namespace App\Notifications;

use App\Models\StudentWarning;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A private word with one student.
 *
 * Written to be read by somebody who is probably embarrassed. It says what was
 * noticed, what happens next, and that they can reply. A message that only
 * threatens gets deleted and changes nothing.
 */
class WarningIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public StudentWarning $warning) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(match ($this->warning->level) {
                'notice' => 'A quick note about class',
                'warning' => 'About your participation in class',
                default => 'We need to talk about your participation',
            })
            ->greeting("Hello {$notifiable->name},");

        $message->line(match ($this->warning->level) {
            'notice' => 'Your trainer mentioned something after the last class, and we would rather tell you directly than let it build up.',
            'warning' => 'This is the second time we have raised this, so we are putting it in writing.',
            default => 'We have raised this twice already and it has not changed, so we are escalating it.',
        });

        $message->line('**What was noticed:** '.$this->warning->reason);

        $message->line(match ($this->warning->level) {
            'notice' => 'Nothing else happens from this. It is a nudge, not a mark against you.',
            'warning' => 'If it continues, the next step is that we contact the guardian on your record.',
            default => 'We are contacting the guardian on your record. We would much rather be working with you on it.',
        });

        return $message
            ->action('Open it in your dashboard', url('/student/warnings'))
            ->line('If something is going on that we should know about, reply to this and tell us. That is genuinely the better outcome for everybody.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'warning.issued',
            'level' => $this->warning->level,
            'reason' => $this->warning->reason,
            'url' => '/student/warnings',
        ];
    }
}
