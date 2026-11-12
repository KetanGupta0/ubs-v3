<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in for the restored ubs-v2 database: v2's table shapes, filled only
 * with made up rows. The importer is never tested against real data.
 *
 * Shapes follow ubs-v2's schema migrations. Add a table here when a step
 * starts reading it.
 */
class LegacyDatabase
{
    public static function create(): void
    {
        config(['database.connections.legacy' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('legacy');

        $schema = Schema::connection('legacy');

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('student');
            $table->string('google_id')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->boolean('has_password')->default(false);
            $table->string('phone', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('qualification')->nullable();
            $table->text('address')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('blocked_at')->nullable();
            $table->string('block_reason', 500)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });
    }

    /** A made up v2 user. */
    public static function user(array $overrides = []): int
    {
        static $n = 0;
        $n++;

        return DB::connection('legacy')->table('users')->insertGetId([
            'name' => "Student {$n}",
            'email' => "student{$n}@example.test",
            'role' => 'student',
            'has_password' => true,
            'password' => password_hash('old-site-password', PASSWORD_BCRYPT, ['cost' => 4]),
            'phone' => '98765'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'city' => 'Lucknow',
            'state' => 'Uttar Pradesh',
            'pincode' => '226001',
            'qualification' => 'B.Tech',
            'created_at' => '2026-05-20 10:00:00',
            'updated_at' => '2026-05-20 10:00:00',
            ...$overrides,
        ]);
    }
}
