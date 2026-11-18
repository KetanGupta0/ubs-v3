<?php

namespace App\Notifications;

use App\Mail\CampaignMail;
use App\Models\MailCampaign;
use Illuminate\Notifications\Notification;

/**
 * A greeting or an announcement, among an account holder's notifications
 * (§8). In the platform only: the mail goes separately, inside its budget, and
 * nobody gets both twice. Not queued: one row each, written as the send starts.
 */
class CampaignPosted extends Notification
{
    public function __construct(public MailCampaign $campaign) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => "mail.{$this->campaign->kind}",
            'title' => CampaignMail::fill($this->campaign->subject, $notifiable->name ?? null),
            'url' => "/messages/{$this->campaign->id}",
        ];
    }
}
