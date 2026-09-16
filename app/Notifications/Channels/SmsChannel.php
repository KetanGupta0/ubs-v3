<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;

/**
 * Sends a notification as an SMS.
 *
 * Referenced by class name from a notification's `via`, so there is nothing to
 * register in a service provider and nothing that can silently fail to be
 * registered. The destination comes from the notifiable's
 * `routeNotificationForSms`, never from the notification itself.
 */
class SmsChannel
{
    public function __construct(protected SmsSender $sender) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('sms', $notification);

        if (blank($to) || ! method_exists($notification, 'toSms')) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if (filled($message)) {
            $this->sender->send($to, (string) $message);
        }
    }
}
