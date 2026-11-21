<?php

namespace App\Services\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Deletes a student or client account that holds nothing worth keeping.
 *
 * Almost every table that points at a user cascades, so deleting somebody with
 * a record would quietly take their payments, invoices, enrolments,
 * certificates and messages with them, the documents this platform promises
 * never to lose. So an account is deleted only when nothing outside OWNED
 * points at it; anything else is a blocker, named, and suspending is the way to
 * shut such an account. The blockers are read from the database's own foreign
 * keys, so a table added later blocks by default until somebody decides it
 * belongs to the account.
 *
 * Meant for accounts made by bots or by mistake (owner's request, 2026-11-21).
 * The deletion is permanent, not soft, so the address is free again, and the
 * audit log keeps who was deleted, by whom and when.
 */
class AccountDeleter
{
    /**
     * Rows that belong only to the account and go with it (or keep their
     * history with the person blanked, where the key is set null).
     */
    public const OWNED = [
        'activity_logs',
        'auth_audit_logs',
        'carts',
        'credential_deliveries',
        'mail_campaign_recipients',
        'otp_codes',
        'permission_user',
        'registrations',
        'reminders_sent',
        'report_schedules',
        'saved_views',
        'social_accounts',
        'user_profiles',
    ];

    /** @var array<int, array{0: string, 1: string}>|null */
    protected static ?array $references = null;

    public function __construct(protected Auditor $auditor) {}

    /**
     * What keeps the account, as "3 enrollments", "1 invoice".
     *
     * @return array<int, string>
     */
    public function blockers(User $user): array
    {
        $found = [];

        foreach ($this->references() as [$table, $column]) {
            if (in_array($table, self::OWNED, true)) {
                continue;
            }

            $count = DB::table($table)->where($column, $user->id)->count();

            if ($count > 0) {
                $found[$table] = ($found[$table] ?? 0) + $count;
            }
        }

        return collect($found)
            ->map(fn (int $count, string $table) => $count.' '.Str::of($table)->replace('_', ' ')->singular()->plural($count))
            ->values()
            ->all();
    }

    public function delete(User $user): void
    {
        if (! in_array($user->role, [Role::Student, Role::Client], true) || $user->is_owner) {
            throw new RuntimeException('Only a student or client account can be deleted.');
        }

        if ($this->blockers($user) !== []) {
            throw new RuntimeException('This account holds records and cannot be deleted.');
        }

        DB::transaction(function () use ($user) {
            $this->auditor->deleted($user, "{$user->name} <{$user->email}> ({$user->role->value})");

            // Keyed by the user without a foreign key, so nothing cascades them.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();
            $user->notifications()->delete();

            $user->forceDelete();
        });
    }

    /** @return array<int, array{0: string, 1: string}> every (table, column) that points at users */
    protected function references(): array
    {
        return self::$references ??= collect(Schema::getTableListing(schemaQualified: false))
            ->flatMap(fn (string $table) => collect(Schema::getForeignKeys($table))
                ->filter(fn (array $key) => $key['foreign_table'] === 'users' && count($key['columns']) === 1)
                ->map(fn (array $key) => [$table, $key['columns'][0]]))
            ->values()
            ->all();
    }
}
