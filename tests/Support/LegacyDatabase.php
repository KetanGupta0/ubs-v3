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

    /** v2's study materials and notices. */
    public static function createContent(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('study_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('pdf');
            $table->string('file_path');
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('uploaded_by')->nullable();
            $table->timestamps();
        });

        $schema->create('notices', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body');
            $table->string('type')->default('info');
            $table->string('target_type')->default('all');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
        });
    }

    /** v2's quizzes and assignments. */
    public static function createAssessment(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('passing_score')->default(75);
            $table->unsignedTinyInteger('max_attempts')->default(1);
            $table->dateTime('due_at')->nullable();
            $table->boolean('show_answers_after')->default(true);
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        $schema->create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id');
            $table->text('question');
            $table->string('type')->default('mcq');
            $table->json('options')->nullable();
            $table->json('correct_answer')->nullable();
            $table->unsignedSmallInteger('marks')->default(1);
            $table->text('explanation')->nullable();
            $table->integer('sort_order')->default(0);
        });

        $schema->create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id');
            $table->foreignId('student_id');
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('score', 6, 2)->default(0);
            $table->decimal('total_marks', 6, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->boolean('is_graded')->default(false);
            $table->boolean('auto_graded')->default(false);
            $table->json('question_order')->nullable();
        });

        $schema->create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id');
            $table->foreignId('question_id');
            $table->json('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('marks_awarded', 5, 2)->default(0);
            $table->text('grader_note')->nullable();
        });

        $schema->create('quiz_attempt_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id');
            $table->foreignId('student_id');
            $table->unsignedTinyInteger('extra_attempts')->default(1);
            $table->text('reason')->nullable();
            $table->foreignId('granted_by')->nullable();
            $table->timestamps();
        });

        $schema->create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->longText('instructions')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->unsignedSmallInteger('max_marks')->default(100);
            $table->boolean('file_required')->default(true);
            $table->json('allowed_types')->nullable();
            $table->timestamps();
        });

        $schema->create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id');
            $table->foreignId('student_id');
            $table->unsignedTinyInteger('submission_number')->default(1);
            $table->boolean('is_latest')->default(true);
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('marks', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('graded_by')->nullable();
            $table->timestamp('resubmission_requested_at')->nullable();
            $table->boolean('resubmission_allowed')->default(false);
        });
    }

    /** v2's certificates. */
    public static function createCertificates(): void
    {
        Schema::connection('legacy')->create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('batch_id')->nullable();
            $table->foreignId('program_id');
            $table->foreignId('template_id')->nullable();
            $table->string('certificate_number')->unique();
            $table->string('token', 128)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->boolean('is_legacy')->default(false);
            $table->string('pdf_path', 500)->nullable();
            $table->timestamps();
        });
    }

    /** v2's payments. */
    public static function createPayments(): void
    {
        Schema::connection('legacy')->create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('enrollment_id')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('INR');
            $table->string('method')->default('razorpay');
            $table->string('razorpay_order_id')->nullable();
            $table->string('razorpay_payment_id')->nullable();
            $table->string('razorpay_signature')->nullable();
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable();
            $table->timestamps();
        });
    }

    /** v2's support tickets. */
    public static function createSupport(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->string('subject');
            $table->string('status')->default('open');
            $table->string('priority')->default('medium');
            $table->string('category')->nullable();
            $table->timestamp('last_replied_at')->nullable();
            $table->timestamps();
        });

        $schema->create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id');
            $table->foreignId('sender_id');
            $table->string('sender_type')->default('student');
            $table->longText('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });
    }

    /** v2's reviews, enquiries and site content. */
    public static function createContentAndFeedback(): void
    {
        $schema = Schema::connection('legacy');

        $schema->create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id');
            $table->foreignId('program_id');
            $table->foreignId('enrollment_id')->nullable();
            $table->unsignedTinyInteger('rating')->default(5);
            $table->string('title')->nullable();
            $table->text('body');
            $table->string('status')->default('pending');
            $table->text('admin_reply')->nullable();
            $table->timestamp('admin_replied_at')->nullable();
            $table->timestamps();
        });

        $schema->create('contact_queries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('subject', 200);
            $table->text('message');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('unread');
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        $schema->create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 500);
            $table->text('answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('team_members', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('role', 200);
            $table->text('bio')->nullable();
            $table->string('initials', 5)->default('');
            $table->string('avatar_url', 500)->nullable();
            $table->string('experience', 50)->nullable();
            $table->string('tech_stack', 300)->nullable();
            $table->string('type')->default('mentor');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $schema->create('custom_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_published')->default(false);
            $table->boolean('show_in_nav')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $schema->create('page_metas', function (Blueprint $table) {
            $table->id();
            $table->string('page_key')->unique();
            $table->string('page_label');
            $table->string('page_url');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();
        });

        $schema->create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });

        $schema->create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('html_body');
            $table->timestamps();
        });
    }

    /** A made up copy of v2's storage directory, in a temporary folder. */
    public static function storage(array $files): string
    {
        $root = sys_get_temp_dir().'/legacy-storage-'.uniqid();

        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$root}/{$path}"), 0777, true);
            file_put_contents("{$root}/{$path}", $contents);
        }

        return $root;
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
