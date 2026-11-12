<?php

namespace App\Services\Legacy\Steps;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Admin\Auditor;
use App\Services\Legacy\Context;
use App\Services\Legacy\Ledger;
use App\Support\Identifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * v2 users → v3 users, profiles and Google links.
 *
 * Passwords follow who the account came from:
 *  - generation 1 (brought into v2 from the first site, hashes published):
 *    a random password nobody ever sees, and a change forced at first sign in;
 *  - generation 2 (created on v2): the hash is copied as it is, so the
 *    student signs in exactly as before;
 *  - an account with no password (Google only) keeps none.
 *
 * A password is only ever set when the account is created. A re-run never
 * changes one, so a credential sent out between rehearsals keeps working.
 *
 * Administrators come across as administrators with no permissions; the
 * owner grants what each needs. Nobody becomes the owner by being imported.
 */
class UsersStep extends Step
{
    public const ROLES = ['student' => Role::Student, 'admin' => Role::Admin, 'client' => Role::Client];

    /** @var array<string, int> mobile => source id, to catch duplicates within the import */
    protected array $mobiles = [];

    public function name(): string
    {
        return 'users';
    }

    public function run(Context $context): void
    {
        $report = $context->report;

        $context->source()->table('users')->orderBy('id')->each(function (object $row) use ($context, $report) {
            $report->count('users', 'read');
            $source = "users#{$row->id}";
            $role = self::ROLES[strtolower((string) $row->role)] ?? null;
            $email = Str::lower(trim((string) $row->email));

            if (! $role) {
                $report->count('users', 'skipped');
                $report->warn('users', 'unknown_role', $source, 'Role is not student, admin or client; not imported.');

                return;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $report->count('users', 'skipped');
                $report->warn('users', 'bad_email', $source, 'Email address is not valid; not imported.');

                return;
            }

            $checksum = Ledger::checksum($row);
            $entry = $context->ledger->find('users', (int) $row->id);

            if ($entry && $entry->source_checksum === $checksum) {
                $report->count('users', 'unchanged');

                return;
            }

            $user = $entry ? User::query()->withTrashed()->find($entry->target_id) : null;
            $existing = ! $user ? User::query()->withTrashed()->where('email', $email)->first() : null;

            if ($existing) {
                // Somebody already holds this email in v3, such as the owner's
                // own account. Link to it and leave its password and role alone.
                $context->ledger->record('users', (int) $row->id, $existing, $checksum, $this->generation($context, $email));
                $report->count('users', 'skipped');
                $report->warn('users', 'matched_existing', $source, 'An account with this email already exists in v3; linked to it, its password and role left as they are.');

                return;
            }

            $generation = $this->generation($context, $email);
            $mobile = $this->mobile($row, $source, $user, $context);
            $isNew = $user === null;
            $user ??= new User;

            $user->forceFill([
                'name' => trim((string) $row->name) ?: 'Student',
                'email' => $email,
                'mobile' => $mobile,
                'role' => $role,
                'status' => ($row->is_blocked ?? false) ? UserStatus::Suspended : UserStatus::Active,
                'email_verified_at' => $row->email_verified_at,
            ]);

            if ($isNew) {
                $user->forceFill([
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }

            $user->save();

            if ($isNew) {
                $this->password($user, $row, $generation);
            }

            $this->profile($user, $row);
            $this->google($user, $row);

            if (($row->is_blocked ?? false) && $isNew) {
                app(Auditor::class)->action('user.suspended_on_import', $user, [
                    'reason' => ['from' => null, 'to' => $row->block_reason ?: 'Blocked on the old site, no reason recorded.'],
                ], "Suspended on the old site ({$source})");
            }

            if (filled($row->bio ?? null)) {
                $report->warn('users', 'bio_not_carried', $source, 'Has a bio on the old site; v3 has no place for it, so it was not carried over.');
            }

            $context->ledger->record('users', (int) $row->id, $user, $checksum, $generation);
            $report->count('users', $isNew ? 'created' : 'updated');
        });
    }

    protected function generation(Context $context, string $email): int
    {
        return $context->isGenerationOne($email) ? 1 : 2;
    }

    /**
     * Written with the query builder, because the model's `hashed` cast would
     * hash an already hashed v2 password a second time and lock the student out.
     */
    protected function password(User $user, object $row, int $generation): void
    {
        $password = match (true) {
            ! ($row->has_password ?? true) || blank($row->password) => null,
            $generation === 1 => Hash::make(Str::random(48)),
            default => $row->password,
        };

        DB::table('users')->where('id', $user->id)->update([
            'password' => $password,
            'must_change_password' => $generation === 1 && $password !== null,
        ]);
    }

    /**
     * The mobile number, normalised. One that is malformed, or that another
     * account already holds, is left empty and listed: mobiles are unique,
     * and guessing which of two people a number belongs to is not the
     * importer's call.
     */
    protected function mobile(object $row, string $source, ?User $user, Context $context): ?string
    {
        if (blank($row->phone ?? null)) {
            return null;
        }

        $mobile = Identifier::normaliseMobile((string) $row->phone);

        if (! preg_match('/^\+\d{11,15}$/', $mobile)) {
            $context->report->warn('users', 'bad_mobile', $source, 'Mobile number is not valid; imported without one.');

            return null;
        }

        $takenHere = isset($this->mobiles[$mobile]) && $this->mobiles[$mobile] !== (int) $row->id;
        $takenInV3 = User::query()->withTrashed()->where('mobile', $mobile)->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists();

        if ($takenHere || $takenInV3) {
            $context->report->warn('users', 'duplicate_mobile', $source, 'Mobile number is already held by another account; imported without one.');

            return null;
        }

        $this->mobiles[$mobile] = (int) $row->id;

        return $mobile;
    }

    protected function profile(User $user, object $row): void
    {
        UserProfile::query()->updateOrCreate(['user_id' => $user->id], [
            'qualification' => $row->qualification ?? null,
            'date_of_birth' => filled($row->date_of_birth ?? null) ? Carbon::parse($row->date_of_birth)->toDateString() : null,
            'address_line_1' => filled($row->address ?? null) ? Str::limit(trim($row->address), 250, '') : null,
            'address_line_2' => filled($row->address_line2 ?? null) ? Str::limit(trim($row->address_line2), 250, '') : null,
            'city' => $row->city ?? null,
            'state' => $row->state ?? null,
            'postal_code' => $row->pincode ?? null,
            'country' => filled($row->country ?? null) ? $row->country : 'India',
        ]);
    }

    protected function google(User $user, object $row): void
    {
        if (blank($row->google_id ?? null)) {
            return;
        }

        SocialAccount::query()->updateOrCreate(
            ['provider' => 'google', 'provider_user_id' => (string) $row->google_id],
            [
                'user_id' => $user->id,
                'email' => $user->email,
                'avatar' => $row->avatar ?? null,
                'linked_at' => $row->created_at ?? now(),
            ],
        );
    }
}
