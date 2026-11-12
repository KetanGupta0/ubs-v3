<?php

namespace App\Notifications;

use App\Models\Enrollment;
use App\Models\Registration;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A registration was approved and the student is on the course.
 *
 * One message, carrying the fee when there is one, rather than an approval
 * followed by a separate payment request a minute later.
 */
class RegistrationApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Registration $registration,
        public Enrollment $enrolment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $course = $this->enrolment->course->title;
        $batch = $this->enrolment->batch?->name;
        $request = $this->enrolment->has_paid ? null : $this->enrolment->paymentRequest;

        $message = (new MailMessage)
            ->subject("You are on {$course}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your registration for **{$course}**".($batch ? " ({$batch})" : '').' has been approved.');

        if ($request) {
            return $message
                ->line('The fee of '.Money::display($request->total).' is waiting on your account. The lessons that need it open once it is paid.')
                ->action('View and pay', url("/student/payments/{$request->id}"));
        }

        return $message->action('Open the course', url("/student/courses/{$this->enrolment->course_id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'registration.approved',
            'title' => 'You are on '.$this->enrolment->course->title,
            'url' => "/student/courses/{$this->enrolment->course_id}",
        ];
    }
}
