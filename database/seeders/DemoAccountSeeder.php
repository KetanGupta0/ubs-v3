<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per role, for local development.
 *
 * Refuses to run in production. These credentials are published in the
 * repository, so a production copy of them would be an open door.
 */
class DemoAccountSeeder extends Seeder
{
    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Demo accounts are never seeded in production.');

            return;
        }

        $accounts = [
            ['admin@unboundbyte.test', '+919000000001', 'Ketan Gupta', Role::Admin],
            ['client@unboundbyte.test', '+919000000002', 'Meridian Logistics', Role::Client],
            ['student@unboundbyte.test', '+919000000003', 'Rahul Verma', Role::Student],
        ];

        foreach ($accounts as [$email, $mobile, $name, $role]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'mobile' => $mobile,
                    'password' => self::PASSWORD,
                    'role' => $role,
                    'status' => UserStatus::Active,
                    'email_verified_at' => now(),
                    'mobile_verified_at' => now(),
                ],
            );

            $user->profile()->firstOrCreate([]);
        }

        $this->command?->info('Demo accounts ready. Password for all three: '.self::PASSWORD);
    }
}
