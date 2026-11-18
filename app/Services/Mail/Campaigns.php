<?php

namespace App\Services\Mail;

use App\Mail\CampaignMail;
use App\Models\MailCampaign;
use App\Models\MailCampaignRecipient;
use App\Models\MailSuppression;
use App\Models\User;
use App\Notifications\CampaignPosted;
use App\Services\Admin\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * Sending greetings and announcements (§8), a few at a time.
 *
 * Starting a send fixes who it is for, once, and puts it among the
 * notifications of everybody with an account straight away. The mail itself
 * goes out from the scheduler inside a daily budget kept below the hosting's
 * cap (X8), greetings before announcements and people without an account
 * first, because the mail is the only way to reach them. The do-not-mail list
 * and the weekly limit are checked again just before each one goes.
 */
class Campaigns
{
    public function __construct(
        protected Audience $audience,
        protected Auditor $auditor,
    ) {}

    /** Fix who it is for, and tell those with an account in the platform at once. */
    public function start(MailCampaign $campaign, ?User $by = null): void
    {
        $started = DB::transaction(function () use ($campaign) {
            $locked = MailCampaign::query()->lockForUpdate()->findOrFail($campaign->id);

            if (! in_array($locked->status, ['draft', 'scheduled'], true)) {
                return null;
            }

            $people = $this->audience->recipients($locked->audience ?? []);
            $suppressed = MailSuppression::query()->whereIn('email', $people->pluck('email'))->pluck('email')->flip();
            $now = now();

            foreach ($people->chunk(500) as $chunk) {
                MailCampaignRecipient::query()->insert($chunk->map(fn (array $person) => [
                    'campaign_id' => $locked->id,
                    'email' => $person['email'],
                    'name' => $person['name'],
                    'user_id' => $person['user_id'],
                    'lead_id' => $person['lead_id'],
                    'status' => $suppressed->has($person['email']) ? 'skipped' : 'queued',
                    'skip_reason' => $suppressed->has($person['email']) ? 'suppressed' : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            $locked->forceFill(['status' => 'sending', 'started_at' => $now])->save();

            return $locked;
        });

        if (! $started) {
            return;
        }

        $this->auditor->actingAs($by)->action('mail.campaign_started', $started, ['recipients' => $started->recipients()->count()], $started->subject);

        // In the platform for everybody with an account, whatever the mail budget.
        $accounts = User::query()->whereIn('id', $started->recipients()->where('status', 'queued')->whereNotNull('user_id')->pluck('user_id'))->get();

        if ($accounts->isNotEmpty()) {
            Notification::send($accounts, new CampaignPosted($started));
        }

        if (! $started->recipients()->where('status', 'queued')->exists()) {
            $this->finish($started);
        }
    }

    /** Send what today's budget allows. Returns how many went. */
    public function sendDue(): int
    {
        $this->expire();

        $limit = min($this->budgetLeft(), (int) config('company.mail.per_run'));
        $sent = 0;

        if ($limit <= 0) {
            return 0;
        }

        $campaigns = MailCampaign::query()->sending()
            ->orderByRaw("case when kind = 'greeting' then 0 else 1 end")
            ->orderBy('started_at')
            ->get();

        foreach ($campaigns as $campaign) {
            $queued = $campaign->recipients()
                ->where('status', 'queued')
                ->orderByRaw('case when user_id is null then 0 else 1 end')
                ->orderBy('id')
                ->limit($limit - $sent)
                ->get();

            foreach ($queued as $recipient) {
                $outcome = $this->deliver($campaign, $recipient);

                if ($outcome === 'sent') {
                    $sent++;
                }

                if ($outcome === 'failed' || $sent >= $limit) {
                    // A refusal may be the mail server's own limit: try again next time.
                    break 2;
                }
            }

            if (! $campaign->recipients()->where('status', 'queued')->exists()) {
                $this->finish($campaign);
            }
        }

        return $sent;
    }

    /** @return string sent, skipped or failed */
    protected function deliver(MailCampaign $campaign, MailCampaignRecipient $recipient): string
    {
        if (MailSuppression::has($recipient->email)) {
            $recipient->forceFill(['status' => 'skipped', 'skip_reason' => 'suppressed'])->save();

            return 'skipped';
        }

        if ($this->sentThisWeek($recipient->email) >= (int) config('company.mail.promotional_per_week')) {
            $recipient->forceFill(['status' => 'skipped', 'skip_reason' => 'weekly_cap'])->save();

            return 'skipped';
        }

        try {
            Mail::to($recipient->email, $recipient->name)->send(new CampaignMail($campaign, [
                'email' => $recipient->email,
                'name' => $recipient->name,
                'why' => CampaignMail::whyFor($recipient),
            ]));

            $recipient->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();

            return 'sent';
        } catch (Throwable $e) {
            report($e);
            Log::warning('A greeting or announcement could not be sent', ['campaign' => $campaign->id, 'recipient' => $recipient->id]);

            // Twice refused is not going to work: stop trying this address.
            $recipient->forceFill([
                'status' => $recipient->error ? 'failed' : 'queued',
                'error' => str($e->getMessage())->limit(290)->toString(),
            ])->save();

            return 'failed';
        }
    }

    /** What is left of today's promotional budget. */
    public function budgetLeft(): int
    {
        $sentToday = MailCampaignRecipient::query()->where('status', 'sent')->where('sent_at', '>=', today())->count();

        return max(0, (int) config('company.mail.promotional_daily_limit') - $sentToday);
    }

    public function sentThisWeek(string $email): int
    {
        return MailCampaignRecipient::query()
            ->where('email', $email)
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->subDays(7))
            ->count();
    }

    /** A greeting is no use after its day: what has not gone by then does not go. */
    public function expire(): void
    {
        MailCampaign::query()->sending()->whereNotNull('expires_at')->where('expires_at', '<', now())->get()
            ->each(function (MailCampaign $campaign) {
                $campaign->recipients()->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'expired', 'updated_at' => now()]);
                $this->finish($campaign);
            });
    }

    public function cancel(MailCampaign $campaign, User $by): void
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'sending'], true)) {
            throw new InvalidArgumentException('This has already gone out.');
        }

        DB::transaction(function () use ($campaign) {
            $campaign->recipients()->where('status', 'queued')->update(['status' => 'skipped', 'skip_reason' => 'cancelled', 'updated_at' => now()]);
            $campaign->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        });

        $this->auditor->actingAs($by)->action('mail.campaign_cancelled', $campaign, label: $campaign->subject);
    }

    protected function finish(MailCampaign $campaign): void
    {
        $campaign->forceFill(['status' => 'sent', 'finished_at' => now()])->save();
    }

    /** To the person setting it up, now, outside the budget and the record. */
    public function testSend(MailCampaign $campaign, User $to): void
    {
        Mail::to($to->email, $to->name)->send(new CampaignMail($campaign, [
            'email' => $to->email,
            'name' => $to->name,
            'why' => 'This is a test, sent to you from the admin panel.',
        ], test: true));
    }

    /** The mail as a recipient would see it. */
    public function preview(MailCampaign $campaign, User $as): string
    {
        return (new CampaignMail($campaign, [
            'email' => $as->email,
            'name' => $as->name,
            'why' => 'You have an account with '.config('company.name').'.',
        ]))->render();
    }
}
