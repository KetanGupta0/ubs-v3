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

    /** v2's programs, batches, enrolments, classes and attendance. */
    public static function createDelivery(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('training_programs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('short_description')->nullable();
            $table->longText('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('duration')->nullable();
            $table->string('level')->nullable();
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->json('curriculum')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_enrollment_open')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $schema->create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('schedule')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('max_students')->default(30);
            $table->string('live_class_platform')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('program_id');
            $table->foreignId('batch_id')->nullable();
            $table->string('status')->default('pending_payment');
            $table->string('student_name');
            $table->string('student_email');
            $table->string('student_phone', 20)->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->timestamp('enrolled_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->boolean('certificate_bypass')->default(false);
            $table->text('bypass_reason')->nullable();
            $table->timestamps();
        });

        $schema->create('batch_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->foreignId('student_id');
            $table->timestamp('joined_at')->nullable();
        });

        $schema->create('live_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('join_url');
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('recording_url')->nullable();
            $table->boolean('is_cancelled')->default(false);
            $table->timestamps();
        });

        $schema->create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_class_id');
            $table->foreignId('student_id');
            $table->timestamp('joined_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->boolean('is_manual')->default(false);
        });
    }

    public static function insert(string $table, array $row): int
    {
        return DB::connection('legacy')->table($table)->insertGetId($row);
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
