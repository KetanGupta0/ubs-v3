<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proctored quizzes (§9.1a): sat in full screen in scheduled sittings, with a
 * member of staff watching each student's status live and able to message
 * them, give them more time or end the attempt.
 *
 * Lockdown only for now (X12): a browser cannot be made to give up control of
 * the computer, so what cannot be enforced is noticed, covered and logged. A
 * final assessment can require Safe Exam Browser, which can (X10). The camera
 * joins when there is a video relay to carry it, and nothing is ever recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // off, lockdown, or live (lockdown with the camera, once there is a relay).
            $table->string('proctoring', 10)->default('off')->after('answers_after_close');
            // Submitted for them after leaving the quiz this many times. Null: never.
            $table->unsignedTinyInteger('max_exits')->nullable()->after('proctoring');
            // Safe Exam Browser: required, and the keys its requests are checked against.
            $table->boolean('require_seb')->default(false)->after('max_exits');
            $table->json('seb_config_keys')->nullable()->after('require_seb');
            $table->json('seb_exam_keys')->nullable()->after('seb_config_keys');
            // The settings file a student's computer opens it with.
            $table->string('seb_config_path')->nullable()->after('seb_exam_keys');
        });

        // When a proctored quiz may be started, and so when somebody is watching.
        Schema::create('quiz_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            // One batch, or everybody the quiz is set for.
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            // Nobody's attempt runs past this.
            $table->timestamp('ends_at');
            $table->string('note', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['quiz_id', 'starts_at']);
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->foreignId('quiz_slot_id')->nullable()->after('batch_id')->constrained()->nullOnDelete();
            // How it was sat, fixed when it started, whatever the quiz says later.
            $table->string('proctoring', 10)->nullable()->after('quiz_slot_id');
            $table->timestamp('consented_at')->nullable()->after('proctoring');
            // Last heard from, and what the page said about itself then.
            $table->timestamp('seen_at')->nullable()->after('last_ip');
            $table->json('live_state')->nullable()->after('seen_at');
            // Ended by the proctor, or for leaving too often, and why.
            $table->foreignId('ended_by')->nullable()->after('flagged_at')->constrained('users')->nullOnDelete();
            $table->string('ended_reason', 500)->nullable()->after('ended_by');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quiz_slot_id');
            $table->dropConstrainedForeignId('ended_by');
            $table->dropColumn(['proctoring', 'consented_at', 'seen_at', 'live_state', 'ended_reason']);
        });

        Schema::dropIfExists('quiz_slots');

        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['proctoring', 'max_exits', 'require_seb', 'seb_config_keys', 'seb_exam_keys', 'seb_config_path']);
        });
    }
};
