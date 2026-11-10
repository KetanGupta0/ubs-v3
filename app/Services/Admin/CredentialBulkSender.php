<?php

namespace App\Services\Admin;

use App\Jobs\SendCredentials;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sends fresh credentials to many accounts at once.
 *
 * Built for the accounts brought across from the old platform, which arrive
 * with a random password nobody knows, but it works for any group an
 * administrator selects.
 *
 * A send replaces the account's password. So the one thing this must never do
 * is reach somebody who is already using their account with a password they
 * chose: that would lock them out of it. Everybody like that is skipped, and
 * the check is made again when each message actually goes, because the queue
 * can run minutes after the button was pressed.
 */
class CredentialBulkSender
{
    public const SKIP_SUSPENDED = 'suspended';

    public const SKIP_IN_USE = 'in_use';

    public const SKIP_RECENT = 'recent';

    public function __construct(protected Auditor $auditor, protected AccountCreator $creator) {}

    /**
     * Why this account should not be sent credentials now, or null if it should.
     *
     * @param  array<int, int>  $ownDeliveryIds  The rows a waiting job planned for itself,
     *                                           which are not a reason for that job to stop.
     */
    public function skipReason(User $user, array $ownDeliveryIds = []): ?string
    {
        if (! $user->canSignIn()) {
            return self::SKIP_SUSPENDED;
        }

        // A password that is not a temporary one is theirs, and a send would
        // replace it. This holds whether or not they have signed in here yet:
        // accounts brought across from the old site keep the password their
        // owners chose there, and the old site never recorded sign ins.
        // Accounts with no password at all (Google only) have nothing to lose.
        if (! $user->must_change_password && $user->password !== null) {
            return self::SKIP_IN_USE;
        }

        $cooldown = (int) config('company.credentials.resend_cooldown_minutes', 15);

        // Still waiting to go out, however long ago it was queued, or sent a
        // moment ago. Either way a second send would reset the password twice.
        $pendingOrRecent = $user->credentialDeliveries()
            ->whereNotIn('id', $ownDeliveryIds)
            ->where(fn ($query) => $query
                ->where('status', 'queued')
                ->orWhere('created_at', '>=', now()->subMinutes($cooldown)))
            ->exists();

        return $pendingOrRecent ? self::SKIP_RECENT : null;
    }

    /**
     * Queue a send for every eligible account, spread over time.
     *
     * @param  Collection<int, User>  $users
     * @return array{queued: int, skipped: array<string, int>}
     */
    public function queue(Collection $users, ?string $personalNote, User $actor): array
    {
        $perMinute = max(1, (int) config('company.credentials.bulk_per_minute', 30));
        $skipped = [];
        $queued = 0;

        foreach ($users as $user) {
            if ($reason = $this->skipReason($user)) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            // Recorded as queued now, so the list shows it straight away and a
            // second click finds it.
            $deliveryIds = collect($this->creator->planDeliveries($user, $actor))->pluck('id')->all();

            SendCredentials::dispatch($user->id, $personalNote, $actor->id, $deliveryIds)
                ->delay(now()->addMinutes(intdiv($queued, $perMinute)));

            $queued++;
        }

        $this->auditor->actingAs($actor)->action('account.credentials_bulk_queued', null, [
            'queued' => $queued,
            'skipped' => $skipped,
            'note' => filled($personalNote),
        ], label: "{$queued} accounts");

        return ['queued' => $queued, 'skipped' => $skipped];
    }
}
