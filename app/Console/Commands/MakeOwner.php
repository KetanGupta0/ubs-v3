<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\Admin\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Names the owner: the one account that holds every permission.
 *
 * `is_owner` is deliberately not fillable and no screen sets it, so a fresh
 * install, or one whose administrators were brought across from v2 (who arrive
 * with no permissions), has nobody who can grant anybody anything. This is the
 * one way it is set, from the server's shell, audited like any other change.
 * There is one owner: naming a second takes `--replace`, which removes the flag
 * from whoever held it.
 */
class MakeOwner extends Command
{
    protected $signature = 'app:owner {email : The staff account to make the owner} {--replace : Take the flag from the current owner}';

    protected $description = 'Make a staff account the owner, who holds every permission';

    public function handle(Auditor $auditor): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user || $user->role !== Role::Admin) {
            $this->error('No staff account has that address. The owner must be an administrator.');

            return self::FAILURE;
        }

        if ($user->status !== UserStatus::Active) {
            $this->error("{$user->email} is {$user->status->value}; make it active first.");

            return self::FAILURE;
        }

        if ($user->is_owner) {
            $this->info("{$user->email} is already the owner.");

            return self::SUCCESS;
        }

        $current = User::query()->where('is_owner', true)->whereKeyNot($user->id)->get();

        if ($current->isNotEmpty() && ! $this->option('replace')) {
            $this->error('There is already an owner: '.$current->pluck('email')->implode(', ').'. Run again with --replace to hand it over.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $current, $auditor) {
            foreach ($current as $previous) {
                $previous->forceFill(['is_owner' => false]);
                $auditor->updated($previous, label: "{$previous->email} (owner removed from the shell)");
                $previous->save();
            }

            $user->forceFill(['is_owner' => true]);
            $auditor->updated($user, label: "{$user->email} (made owner from the shell)");
            $user->save();
        });

        foreach ($current as $previous) {
            $this->line("{$previous->email} is no longer the owner.");
        }

        $this->info("{$user->email} is now the owner.");

        return self::SUCCESS;
    }
}
