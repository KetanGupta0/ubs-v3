<?php

namespace App\Jobs;

use App\Models\CredentialDelivery;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\CredentialBulkSender;
use App\Services\Mail\MailBudget;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One account's share of a bulk credential send.
 *
 * Carries ids rather than models, and looks again at the account before
 * sending: if the person signed in and chose a password while the job waited
 * its turn, sending now would replace it.
 */
class SendCredentials implements ShouldQueue
{
    use Queueable;

    /** Hour of the morning a send that waited for the day's mail goes out. */
    public const MORNING = 9;

    /**
     * Retried for a month, not a number of times: a large send waits for the
     * day's mail limit (X15) by putting itself back until the next morning,
     * which would use up a count of tries in three days. A fault waits longer
     * each time.
     *
     * @var array<int, int>
     */
    public array $backoff = [300, 1800, 3600];

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDays(30);
    }

    public function __construct(
        public int $userId,
        public ?string $personalNote,
        public int $actorId,
        /** @var array<int, int> The rows recorded as queued when this was dispatched. */
        public array $deliveryIds = [],
    ) {}

    public function handle(AccountCreator $creator, CredentialBulkSender $sender): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        $deliveries = CredentialDelivery::query()
            ->whereIn('id', $this->deliveryIds)
            ->where('user_id', $user->id)
            ->where('status', 'queued')
            ->get();

        if ($reason = $sender->skipReason($user, $this->deliveryIds)) {
            Log::info('Bulk credential send skipped at delivery time', [
                'user_id' => $user->id,
                'reason' => $reason,
            ]);

            // Close the rows this job planned, so the list does not show a
            // send as waiting forever.
            $deliveries->each->forceFill([
                'status' => 'skipped',
                'failure_reason' => match ($reason) {
                    CredentialBulkSender::SKIP_IN_USE => 'Chose a password before this went out.',
                    CredentialBulkSender::SKIP_SUSPENDED => 'Suspended before this went out.',
                    default => 'Another send reached this account first.',
                },
            ])->each->save();

            return;
        }

        // Nothing is changed until it can go: the password is only replaced
        // once there is room in the day's mail for the message carrying it.
        if ($deliveries->contains('channel', 'mail') && MailBudget::bulkLeft() <= 0) {
            $this->release($this->secondsUntilMorning());

            return;
        }

        // Handed over now rather than queued again, so each one is counted
        // against the day before the next is weighed.
        $creator->resendCredentials(
            $user,
            $this->personalNote,
            User::query()->find($this->actorId),
            $deliveries->isEmpty() ? null : $deliveries->all(),
            now: true,
        );
    }

    /** Tomorrow at nine, spread over ten minutes so a big send does not all wake at once. */
    protected function secondsUntilMorning(): int
    {
        $morning = today()->addDay()->setTime(self::MORNING, 0)->addSeconds($this->userId % 600);

        return max(60, (int) now()->diffInSeconds($morning, absolute: true));
    }
}
