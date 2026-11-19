<?php

namespace App\Services\Leads;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadAcknowledgement;
use App\Notifications\LeadReceived;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * What happens once an enquiry is recorded, wherever it came from (the
 * contact form, a demo, the assistant): our side is told, and the person who
 * wrote gets an acknowledgement. A provider being down never loses the lead.
 */
class LeadNotifier
{
    public function notify(Lead $lead): void
    {
        $lead->loadMissing(['solution', 'service', 'course']);

        try {
            $admins = User::query()->role(Role::Admin)->active()->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new LeadReceived($lead));
            }

            Notification::route('mail', $lead->email)->notify(new LeadAcknowledgement($lead));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
