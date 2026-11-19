<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\CredentialDelivery;
use App\Models\User;
use App\Notifications\WelcomeCredentials;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates an account on someone's behalf and sends them the way in.
 *
 * Clients never sign themselves up, so this is the only door into a client
 * account, and it has to close three gaps at once: the account exists, the
 * person can reach it, and the credential we generated stops being valid the
 * moment they choose their own.
 *
 * Delivery failures do not roll back the account. A mail provider being down is
 * not a reason to lose a client record an administrator just typed; it is a
 * reason to show them that the message did not go and offer to resend.
 */
class AccountCreator
{
    public function __construct(protected Auditor $auditor) {}

    /**
     * @param  array<string, mixed>  $attributes  name, email, mobile, plus optional profile data.
     * @return array{user: User, password: string, deliveries: array<int, CredentialDelivery>}
     */
    public function create(array $attributes, Role $role, ?string $personalNote = null): array
    {
        $password = TemporaryPassword::generate();

        $user = DB::transaction(function () use ($attributes, $role, $password) {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'mobile' => $attributes['mobile'] ?? null,
                'password' => $password,
                'role' => $role,
                'status' => UserStatus::Active,
                // The password we just generated is not theirs until they
                // replace it, so nothing else is reachable until they do.
                'must_change_password' => true,
            ]);

            $user->profile()->create(array_filter([
                'company' => $attributes['company'] ?? null,
                'designation' => $attributes['designation'] ?? null,
                'gstin' => $attributes['gstin'] ?? null,
                'website' => $attributes['website'] ?? null,
                'address_line_1' => $attributes['address_line_1'] ?? null,
                'city' => $attributes['city'] ?? null,
                'state' => $attributes['state'] ?? null,
                'postal_code' => $attributes['postal_code'] ?? null,
                'college_id' => $attributes['college_id'] ?? null,
                'enrollment_number' => $attributes['enrollment_number'] ?? null,
                'course_of_study' => $attributes['course_of_study'] ?? null,
                'current_semester' => $attributes['current_semester'] ?? null,
                'graduation_year' => $attributes['graduation_year'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));

            return $user;
        });

        $this->auditor->action('account.created', $user, [
            'role' => $role->value,
            'credentials_sent' => true,
        ]);

        return [
            'user' => $user,
            'password' => $password,
            'deliveries' => $this->sendCredentials($user, $password, $personalNote),
        ];
    }

    /**
     * A client account for somebody buying from the shop (CL6).
     *
     * The same door as every other account, recorded the same way, but with
     * nothing to send: they have just proved the address with a code, so the
     * account starts verified and without a password. They sign in with a
     * code, or set a password under Security when they want one, the way an
     * account made with Google does.
     *
     * @param  array<string, mixed>  $attributes  name, email, mobile, company.
     */
    public function createForBuyer(array $attributes): User
    {
        $user = DB::transaction(function () use ($attributes) {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'mobile' => $attributes['mobile'] ?? null,
                'password' => null,
                'role' => Role::Client,
                'status' => UserStatus::Active,
                'must_change_password' => false,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $user->profile()->create(array_filter([
                'company' => $attributes['company'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));

            return $user;
        });

        $this->auditor->actingAs($user)->action('account.created', $user, [
            'role' => Role::Client->value,
            'via' => 'checkout',
        ]);

        return $user;
    }

    /**
     * Issue a fresh temporary password and send it again.
     *
     * Used when the first message did not arrive, and for accounts brought
     * across from the old platform. The old password stops working
     * immediately, so a resend is not a second live credential.
     *
     * Pass the actor when there is no signed in request, as in a queued job,
     * so the audit entry and the delivery record say who sent it. A bulk send
     * records its deliveries as queued up front (see planDeliveries()) and
     * passes them in, so they are completed rather than recorded twice.
     *
     * @param  array<int, CredentialDelivery>|null  $plannedDeliveries
     */
    /**
     * @param  bool  $now  Hand the message over now rather than queue it: the
     *                     bulk send does, so each one is counted against the
     *                     day's mail before the next is weighed (X15), and a
     *                     refusal is recorded as the failure it is.
     */
    public function resendCredentials(User $user, ?string $personalNote = null, ?User $actor = null, ?array $plannedDeliveries = null, bool $now = false): array
    {
        $password = TemporaryPassword::generate();

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
        ])->save();

        // Anything signed in on the old password is cut off.
        $user->tokens()->delete();

        $this->auditor->actingAs($actor)->action('account.credentials_resent', $user);

        return [
            'password' => $password,
            'deliveries' => $this->sendCredentials($user, $password, $personalNote, $actor, $plannedDeliveries, $now),
        ];
    }

    /**
     * Record that credentials are about to go to this account, one row per
     * channel, before anything is generated or sent.
     *
     * @return array<int, CredentialDelivery>
     */
    public function planDeliveries(User $user, ?User $actor = null): array
    {
        $deliveries = [];

        foreach ([['mail', $user->email], ['sms', $user->mobile]] as [$channel, $destination]) {
            if (blank($destination)) {
                continue;
            }

            $deliveries[] = CredentialDelivery::query()->create([
                'user_id' => $user->id,
                'created_by' => $actor?->id ?? request()->user()?->id,
                'channel' => $channel,
                'destination' => $destination,
                'status' => 'queued',
            ]);
        }

        return $deliveries;
    }

    /**
     * @param  array<int, CredentialDelivery>|null  $deliveries  Rows already planned, if any.
     * @return array<int, CredentialDelivery>
     */
    protected function sendCredentials(User $user, string $password, ?string $personalNote, ?User $actor = null, ?array $deliveries = null, bool $now = false): array
    {
        $deliveries ??= $this->planDeliveries($user, $actor);

        try {
            $now
                ? $user->notifyNow(new WelcomeCredentials($user, $password, $personalNote))
                : $user->notify(new WelcomeCredentials($user, $password, $personalNote));

            foreach ($deliveries as $delivery) {
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('Welcome credentials could not be sent', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            foreach ($deliveries as $delivery) {
                $delivery->forceFill([
                    'status' => 'failed',
                    'failure_reason' => str($e->getMessage())->limit(400)->toString(),
                ])->save();
            }
        }

        return $deliveries;
    }
}
