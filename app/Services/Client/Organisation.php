<?php

namespace App\Services\Client;

use App\Enums\Role;
use App\Models\ClientMember;
use App\Models\User;
use App\Notifications\ClientInvitation;
use App\Services\Admin\AccountCreator;
use App\Services\Admin\Auditor;
use App\Services\Chat\Rooms;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Who is in a client organisation (CL8), and how they got there.
 *
 * Bringing somebody in never tells the person doing it whether the address
 * already had an account, the same rule sign in follows:
 *
 * - **A new address** gets an account through `AccountCreator`, which sends
 *   the way in, and is in the organisation from that moment.
 * - **An existing client account with nothing of its own** is invited, and
 *   joins only when its holder accepts. Joining shows them the organisation's
 *   records, so nobody is added to one without saying yes.
 * - **Anything else** (staff, a student, a client with history of their own,
 *   somebody already in an organisation) gets nothing, and the answer on
 *   screen is the same.
 */
class Organisation
{
    public function __construct(
        protected AccountCreator $accounts,
        protected Auditor $auditor,
        protected Rooms $rooms,
    ) {}

    /**
     * @param  array{name: string, email: string, mobile: ?string, role: string}  $person
     * @return string What happened: created, invited or skipped. A client's
     *                screen never shows it; staff are told.
     */
    public function invite(User $account, User $inviter, array $person): string
    {
        $role = $person['role'];

        if (! array_key_exists($role, ClientMember::ROLES)) {
            throw new InvalidArgumentException('Choose a role.');
        }

        $email = mb_strtolower(trim($person['email']));
        $existing = User::query()->where('email', $email)->first();

        $this->auditor->actingAs($inviter)->action('client.member_invited', $account, [
            'email' => $email,
            'role' => $role,
        ], $account->organisationName());

        if (! $existing) {
            $mobile = $person['mobile'] && ! User::query()->where('mobile', $person['mobile'])->exists() ? $person['mobile'] : null;

            DB::transaction(function () use ($account, $inviter, $person, $email, $mobile, $role) {
                $created = $this->accounts->create([
                    'name' => trim($person['name']),
                    'email' => $email,
                    'mobile' => $mobile,
                    'company' => $account->organisationName(),
                ], Role::Client, sprintf(
                    '%s added you to %s as %s. Sign in to see its projects, documents and invoices.',
                    $inviter->name,
                    $account->organisationName(),
                    mb_strtolower(ClientMember::ROLES[$role][0]),
                ));

                ClientMember::query()->create([
                    'account_id' => $account->id,
                    'user_id' => $created['user']->id,
                    'role' => $role,
                    'invited_by' => $inviter->id,
                    'joined_at' => now(),
                ]);
            });

            $this->rooms->syncClientMembers($account);

            return 'created';
        }

        $eligible = $existing->isClient()
            && ! $existing->is($account)
            && ! $existing->clientMembership()->exists()
            && ! $existing->ownsClientRecords();

        if (! $eligible) {
            return 'skipped';
        }

        $membership = ClientMember::query()->create([
            'account_id' => $account->id,
            'user_id' => $existing->id,
            'role' => $role,
            'invited_by' => $inviter->id,
        ]);

        $existing->notify(new ClientInvitation($membership));

        return 'invited';
    }

    public function accept(ClientMember $membership): void
    {
        if ($membership->joined_at) {
            return;
        }

        $membership->forceFill(['joined_at' => now()])->save();

        $this->auditor->actingAs($membership->user)->action('client.member_joined', $membership->account, [
            'role' => $membership->role,
        ], $membership->account->organisationName());

        $this->rooms->syncClientMembers($membership->account);
    }

    public function decline(ClientMember $membership): void
    {
        if ($membership->joined_at) {
            throw new InvalidArgumentException('That invitation has already been accepted.');
        }

        $membership->delete();
    }

    public function changeRole(ClientMember $membership, string $role, User $actor): void
    {
        if (! array_key_exists($role, ClientMember::ROLES)) {
            throw new InvalidArgumentException('Choose a role.');
        }

        $before = $membership->role;
        $membership->forceFill(['role' => $role])->save();

        $this->auditor->actingAs($actor)->action('client.member_role_changed', $membership->user, [
            'role' => ['from' => $before, 'to' => $role],
            'organisation' => $membership->account->organisationName(),
        ], $membership->user->name);
    }

    /**
     * Take somebody out of the organisation, or withdraw an invitation. Their
     * own account stays; it simply no longer opens the organisation's records.
     */
    public function remove(ClientMember $membership, User $actor): void
    {
        $account = $membership->account;
        $person = $membership->user;

        $membership->delete();

        $this->auditor->actingAs($actor)->action('client.member_removed', $person, [
            'organisation' => $account->organisationName(),
            'role' => $membership->role,
        ], $person->name);

        $this->rooms->syncClientMembers($account);
    }
}
