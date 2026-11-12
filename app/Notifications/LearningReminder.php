<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reminder about learning: a class coming up, work closing, classes missed.
 *
 * One class for every kind, because they are all the same shape: a subject,
 * a line or two, and where to go. `App\Services\Lms\Reminders` decides who
 * gets which and makes sure nobody gets the same one twice.
 */
class LearningReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, string>  $lines */
    public function __construct(
        public string $kind,
        public string $subject,
        public array $lines,
        public string $actionText,
        public string $path,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->subject)->greeting("Hello {$notifiable->name},");

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        $message->action($this->actionText, url($this->path));

        if (method_exists($notifiable, 'isStudent') && $notifiable->isStudent()) {
            $message->line('You can switch reminders like this off under Reminders in your account.');
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "reminder.{$this->kind}",
            'title' => $this->subject,
            'url' => $this->path,
        ];
    }
}
