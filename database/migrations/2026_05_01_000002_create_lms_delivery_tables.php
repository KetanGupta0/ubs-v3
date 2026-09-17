<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Running a batch: who is on it, when it meets, who turned up.
 *
 * Attendance is per session and per student rather than a running percentage,
 * because a percentage cannot answer "which classes did I miss", which is the
 * only question a student actually asks about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('active');
            $table->string('source', 30)->default('admin');
            $table->timestamp('enrolled_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);

            /*
             * Whether the fee is settled, kept here rather than looked up
             * through the ledger every time a lesson is opened. A free course
             * and a scholarship both land as paid with nothing owing.
             */
            $table->boolean('has_paid')->default(false);
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'batch_id']);
            $table->index(['batch_id', 'status']);
        });

        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('agenda')->nullable();
            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(90);

            // Every class runs on Google Meet, so the link is the session.
            $table->string('meet_link')->nullable();
            $table->string('recording_url')->nullable();
            $table->string('calendar_event_id')->nullable();

            $table->string('status', 20)->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['batch_id', 'scheduled_at']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('absent');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->unsignedSmallInteger('minutes_attended')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['live_session_id', 'user_id']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('body');
            $table->string('audience', 20)->default('batch');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'published_at']);
        });

        Schema::create('student_warnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('live_session_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Escalating a student is a serious act, so the whole record is
             * kept: who raised it, why, whether the student saw it, and whether
             * a guardian was told. A flag with no reason is not usable later.
             */
            $table->string('level', 20)->default('notice');
            $table->text('reason');
            $table->text('private_note')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('guardian_notified_at')->nullable();
            $table->string('guardian_contact')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'level']);
        });

        Schema::create('leaderboard_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('source', 40);
            $table->nullableMorphs('reason');
            $table->integer('points');
            $table->timestamp('awarded_at');
            $table->timestamps();

            // One award per thing: attending the same class twice is not twice
            // the points.
            $table->unique(['user_id', 'source', 'reason_type', 'reason_id'], 'points_once_per_reason');
            $table->index(['batch_id', 'user_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);
            $table->nullableMorphs('subject');
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('leaderboard_points');
        Schema::dropIfExists('student_warnings');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('live_sessions');
        Schema::dropIfExists('enrollments');
    }
};
