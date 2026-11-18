<?php

namespace App\Services\Mail;

use App\Models\MailCampaign;
use App\Models\Occasion;
use App\Models\User;
use App\Notifications\OccasionDateToConfirm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Greetings on the days the admin set (§8, X7).
 *
 * Sent on the day itself, from its time of day, and never late: a greeting
 * the day after is worse than none, so a day the scheduler missed is skipped
 * and moved on. A national day moves on a year by itself; a festival moves on
 * a year as a guess, and is not sent until somebody confirms the date, with a
 * reminder a month before.
 */
class Greetings
{
    public function __construct(protected Campaigns $campaigns) {}

    /** Start today's greetings whose time has come. Returns how many started. */
    public function startDue(): int
    {
        $started = 0;

        Occasion::query()->where('is_active', true)->whereNotNull('date')->whereDate('date', '<=', today())->get()
            ->each(function (Occasion $occasion) use (&$started) {
                if ($occasion->date->isBefore(today())) {
                    Log::info('A greeting day passed without being sent', ['occasion' => $occasion->id, 'date' => $occasion->date->toDateString()]);
                    $this->moveOn($occasion);

                    return;
                }

                if (! $occasion->date_confirmed) {
                    return; // Waits for a person, and lapses at the end of the day.
                }

                if (now()->format('H:i') < $occasion->send_time) {
                    return;
                }

                $campaign = MailCampaign::query()->create([
                    'kind' => 'greeting',
                    'occasion_id' => $occasion->id,
                    'subject' => $occasion->subject,
                    'message' => $occasion->message,
                    'media_id' => $occasion->media_id,
                    'audience' => ['groups' => $occasion->audience ?? []],
                    'status' => 'scheduled',
                    'scheduled_at' => now(),
                    'expires_at' => today()->endOfDay(),
                ]);

                $occasion->forceFill(['last_sent_on' => $occasion->date])->save();
                $this->moveOn($occasion);
                $this->campaigns->start($campaign);
                $started++;
            });

        return $started;
    }

    /** A month before a date nobody has confirmed, ask somebody who can. */
    public function remindUnconfirmed(): int
    {
        $staff = User::query()->holdingPermission('mail.occasions')->active()->get();
        $reminded = 0;

        Occasion::query()
            ->where('is_active', true)
            ->where('date_confirmed', false)
            ->whereNotNull('date')
            ->whereDate('date', '>=', today())
            ->whereDate('date', '<=', today()->addDays(30))
            ->get()
            ->reject(fn (Occasion $occasion) => $occasion->reminded_for?->isSameDay($occasion->date))
            ->each(function (Occasion $occasion) use ($staff, &$reminded) {
                if ($staff->isNotEmpty()) {
                    Notification::send($staff, new OccasionDateToConfirm($occasion));
                }

                $occasion->forceFill(['reminded_for' => $occasion->date])->save();
                $reminded++;
            });

        return $reminded;
    }

    /** On to next year: the same date for a national day, a date to confirm for a festival. */
    protected function moveOn(Occasion $occasion): void
    {
        $next = Carbon::parse($occasion->date)->addYearNoOverflow();

        $occasion->forceFill([
            'date' => $next,
            'date_confirmed' => $occasion->repeats === 'same_date',
        ])->save();
    }
}
