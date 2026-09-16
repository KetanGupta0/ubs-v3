<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OneTimeCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $code,
        protected OtpPurpose $purpose,
        protected int $minutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = match ($this->purpose) {
            OtpPurpose::Login => 'sign in to your Unboundbyte account',
            OtpPurpose::VerifyEmail => 'confirm your email address',
            OtpPurpose::VerifyMobile => 'confirm your mobile number',
            OtpPurpose::ResetPassword => 'reset your password',
        };

        return (new MailMessage)
            ->subject("{$this->code} is your Unboundbyte code")
            ->greeting('Your verification code')
            ->line("Use this code to {$action}.")
            ->line("**{$this->code}**")
            ->line("The code expires in {$this->minutes} minutes and can be used once.")
            ->line('If you did not request it, you can ignore this message. Nobody from Unboundbyte will ever ask you for this code.');
    }
}
