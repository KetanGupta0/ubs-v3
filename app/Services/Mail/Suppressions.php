<?php

namespace App\Services\Mail;

use App\Models\MailSuppression;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Support\Facades\URL;

/**
 * Who gets no greetings or announcements (§8), by address, whoever asked: the
 * person themselves from a link or their account, or us after a bounce. It is
 * honoured at once, by every send still going out. Service mail never reads it.
 */
class Suppressions
{
    public function __construct(protected Auditor $auditor) {}

    public function suppress(string $email, string $reason, ?int $campaignId = null, ?User $by = null, ?string $note = null): MailSuppression
    {
        $suppression = MailSuppression::query()->firstOrCreate(
            ['email' => MailSuppression::normalise($email)],
            ['reason' => $reason, 'campaign_id' => $campaignId, 'added_by' => $by?->id, 'note' => $note],
        );

        if ($suppression->wasRecentlyCreated && $by?->isAdmin()) {
            $this->auditor->actingAs($by)->action('mail.suppressed', $suppression, ['reason' => $reason], $suppression->email);
        }

        return $suppression;
    }

    /**
     * Mail them again. Somebody who unsubscribed may change their mind; an
     * address that bounced or complained is taken off only by staff.
     */
    public function allow(string $email, ?User $by = null): bool
    {
        $suppression = MailSuppression::query()->where('email', MailSuppression::normalise($email))->first();

        if (! $suppression) {
            return true;
        }

        if ($suppression->reason !== 'unsubscribed' && ! $by?->isAdmin()) {
            return false;
        }

        if ($by?->isAdmin()) {
            $this->auditor->actingAs($by)->action('mail.allowed', $suppression, ['reason' => $suppression->reason], $suppression->email);
        }

        $suppression->delete();

        return true;
    }

    /**
     * The link in every greeting and announcement. It never expires, and its
     * signature covers the path rather than the host, because a link to stop
     * mail has to work for as long as the mail is in somebody's inbox, whatever
     * address the site has by then.
     */
    public static function unsubscribeUrl(string $email, ?int $campaignId = null): string
    {
        return url(URL::signedRoute('mail.unsubscribe', array_filter([
            'address' => self::encode($email),
            'c' => $campaignId,
        ]), absolute: false));
    }

    public static function resubscribeUrl(string $encoded): string
    {
        return url(URL::signedRoute('mail.resubscribe', ['address' => $encoded], absolute: false));
    }

    public static function encode(string $email): string
    {
        return rtrim(strtr(base64_encode(MailSuppression::normalise($email)), '+/', '-_'), '=');
    }

    public static function addressFrom(string $encoded): ?string
    {
        $email = base64_decode(strtr($encoded, '-_', '+/'), true);

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
