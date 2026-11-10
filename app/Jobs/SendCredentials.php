<?php

namespace App\Jobs;

use App\Models\CredentialDelivery;
use App\Models\User;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\CredentialBulkSender;
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

    public int $tries = 3;

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

        $creator->resendCredentials(
            $user,
            $this->personalNote,
            User::query()->find($this->actorId),
            $deliveries->isEmpty() ? null : $deliveries->all(),
        );
    }
}
