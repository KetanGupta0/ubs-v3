<?php

namespace App\Console\Commands;

use App\Models\MailCampaign;
use App\Services\Mail\Campaigns;
use App\Services\Mail\Greetings;
use Illuminate\Console\Command;

/**
 * Greetings and announcements (§8), from the scheduler every few minutes:
 * start today's greetings and the broadcasts whose time has come, then send
 * what the day's budget allows. Once a day, ask about festival dates nobody
 * has confirmed.
 */
class SendPromotionalMail extends Command
{
    protected $signature = 'mail:send-promotional {--remind : Also ask about unconfirmed festival dates}';

    protected $description = 'Start due greetings and broadcasts, and send within the daily budget';

    public function handle(Campaigns $campaigns, Greetings $greetings): int
    {
        if ($this->option('remind')) {
            $this->info('Asked about '.$greetings->remindUnconfirmed().' unconfirmed dates.');
        }

        $greetings->startDue();

        MailCampaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()
            ->each(fn (MailCampaign $campaign) => $campaigns->start($campaign));

        $sent = $campaigns->sendDue();
        $this->info("Sent {$sent}. {$campaigns->budgetLeft()} left in today's budget.");

        return self::SUCCESS;
    }
}
