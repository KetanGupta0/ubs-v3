<?php

namespace App\Services\Mail;

use App\Models\MailCampaignRecipient;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * The day's mail against what the hosting plan allows (X15).
 *
 * The owner sets the plan's daily limit in Settings, and how much of it to
 * keep for service mail: sign in codes, receipts, reminders. Every message the
 * mailer hands over is counted here by recipient, as the mail server counts,
 * so the dashboard can say what is left. Greetings and announcements go only
 * while the day's mail leaves more than the part kept for service mail.
 *
 * Service mail itself is never held back by this count. The mail server
 * decides whether it goes, and mail somebody sends by hand from the same
 * mailbox counts there and not here; holding a sign in code because of an
 * estimate would lock somebody out for nothing.
 */
class MailBudget
{
    public const LIMIT_KEY = 'mail.daily_limit';

    public const RESERVE_KEY = 'mail.service_reserve';

    /** Most a plan is likely to allow; a typo past it is refused on save. */
    public const MAX_LIMIT = 100000;

    public static function limit(): int
    {
        return max(1, (int) Setting::get(self::LIMIT_KEY, config('company.mail.daily_limit')));
    }

    /** Kept for service mail: greetings and announcements never use it. */
    public static function reserve(): int
    {
        return min(self::limit(), max(0, (int) Setting::get(self::RESERVE_KEY, config('company.mail.service_reserve'))));
    }

    /** Counted when the mailer has handed a message over. */
    public static function recordSent(int $recipients, bool $promotional = false): void
    {
        if ($recipients > 0) {
            self::bump(['sent' => $recipients, 'promotional' => $promotional ? $recipients : 0]);
        }
    }

    /** A message the mail server would not take, once its retries are spent. */
    public static function recordRefused(): void
    {
        self::bump(['refused' => 1]);
    }

    /** @return array{sent: int, promotional: int, refused: int} */
    public static function today(): array
    {
        $row = DB::table('mail_usage')->where('day', today()->toDateString())->first();

        return [
            'sent' => (int) ($row->sent ?? 0),
            'promotional' => (int) ($row->promotional ?? 0),
            'refused' => (int) ($row->refused ?? 0),
        ];
    }

    /**
     * What greetings and announcements may still send today: whatever the
     * day's mail has not used, less the part kept for service mail. Greetings
     * marked sent today are a floor for the count, in case one went out
     * without the mailer reporting it.
     */
    public static function promotionalLeft(): int
    {
        return self::bulkLeft();
    }

    /**
     * What mail sent in bulk may still use today: greetings, announcements and
     * a bulk credential send share what the day leaves above the part kept for
     * sign in codes, receipts and reminders.
     */
    public static function bulkLeft(): int
    {
        $sent = max(
            self::today()['sent'],
            MailCampaignRecipient::query()->where('status', 'sent')->where('sent_at', '>=', today())->count(),
        );

        return max(0, self::limit() - self::reserve() - $sent);
    }

    /** Roughly how many days a bulk send of this many messages takes, today included. */
    public static function daysFor(int $messages): int
    {
        $today = self::bulkLeft();

        if ($messages <= $today) {
            return 1;
        }

        return 1 + (int) ceil(($messages - $today) / max(1, self::limit() - self::reserve()));
    }

    /**
     * For the dashboard, the settings and the mail screens.
     *
     * @return array{limit: int, reserve: int, sent: int, promotional: int, refused: int, left: int, promotionalLeft: int, state: string}
     */
    public static function summary(): array
    {
        $usage = self::today();
        $limit = self::limit();
        $left = max(0, $limit - $usage['sent']);

        return [
            'limit' => $limit,
            'reserve' => self::reserve(),
            'sent' => $usage['sent'],
            'promotional' => $usage['promotional'],
            'refused' => $usage['refused'],
            'left' => $left,
            'promotionalLeft' => self::promotionalLeft(),
            // Used up, nearly (a tenth or less left), or fine.
            'state' => match (true) {
                $left === 0 => 'used_up',
                $left <= max(1, intdiv($limit, 10)) => 'low',
                default => 'ok',
            },
        ];
    }

    /** @param  array<string, int>  $by */
    protected static function bump(array $by): void
    {
        $now = now();

        DB::table('mail_usage')->upsert(
            [['day' => today()->toDateString(), 'sent' => 0, 'promotional' => 0, 'refused' => 0, ...$by, 'updated_at' => $now]],
            ['day'],
            [
                ...collect($by)->map(fn (int $count, string $column) => DB::raw($column.' + '.$count))->all(),
                'updated_at' => $now,
            ],
        );
    }
}
