<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Looking at what the checks found (§9.1, layers 3 and 4).
 *
 * An AI reads a flagged attempt, without anybody's name, and says how worried
 * to be and why, pointing at the evidence and at the innocent readings it
 * considered. Then a person decides: clear it, talk to the student, void the
 * attempt and allow a resit, or a warning on the usual ladder. The student is
 * told what was decided and why, and can reply once before it is final.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            // queued, sent, done, failed.
            $table->string('status', 10)->default('queued');
            $table->string('batch_id')->nullable()->index();
            $table->string('model', 60)->nullable();
            // none, low, medium, high.
            $table->string('risk', 10)->nullable();
            $table->text('summary')->nullable();
            $table->json('evidence')->nullable();
            $table->json('explanations')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error', 300)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('quiz_integrity_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->unique()->constrained()->cascadeOnDelete();
            // cleared, discuss, voided, warned.
            $table->string('outcome', 10);
            // What the student reads.
            $table->text('reason')->nullable();
            // For us only; never reaches the student.
            $table->text('note')->nullable();
            $table->boolean('resit')->default(false);
            $table->foreignId('warning_id')->nullable()->constrained('student_warnings')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');
            $table->timestamp('told_at')->nullable();
            // One reply, before it is final.
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('final_at')->nullable();
            $table->timestamps();
        });

        Schema::table('quiz_attempts', function (Blueprint $table) {
            // A voided attempt stays on the record and counts for nothing.
            $table->timestamp('voided_at')->nullable()->after('ended_reason');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });

        Schema::dropIfExists('quiz_integrity_decisions');
        Schema::dropIfExists('quiz_ai_reviews');
    }
};
